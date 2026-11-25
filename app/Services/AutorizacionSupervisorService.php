<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * Autorización en el momento con el PIN personal de un supervisor (p. ej. aplicar un descuento).
 *
 * - El PIN se guarda con hash; identifica a QUIÉN autoriza (queda registrado en la operación).
 * - Solo valen usuarios de la empresa con el permiso pedido.
 * - Máximo 5 intentos fallidos por minuto por cajero: un PIN de 4 dígitos no se adivina a fuerza.
 */
class AutorizacionSupervisorService
{
    public const INTENTOS_POR_MINUTO = 5;

    public function autorizar(Empresa $empresa, User $solicitante, string $pin, string $permiso): User
    {
        $clave = "pin-supervisor:{$empresa->id}:{$solicitante->id}";

        if (RateLimiter::tooManyAttempts($clave, self::INTENTOS_POR_MINUTO)) {
            throw new RuntimeException('Demasiados intentos. Espera '.RateLimiter::availableIn($clave).' segundos.');
        }

        $pin = trim($pin);

        if ($pin !== '') {
            $candidatos = User::where('empresa_id', $empresa->id)->whereNotNull('pin_autorizacion')->get();

            setPermissionsTeamId($empresa->id);
            foreach ($candidatos as $usuario) {
                if (Hash::check($pin, $usuario->pin_autorizacion) && $usuario->unsetRelation('roles')->unsetRelation('permissions')->can($permiso)) {
                    RateLimiter::clear($clave);

                    return $usuario;
                }
            }
        }

        RateLimiter::hit($clave, 60);

        throw new RuntimeException('PIN de supervisor incorrecto o sin permiso para autorizar.');
    }

    /** Valida que el PIN nuevo tenga 4–8 dígitos y no lo use ya otro usuario de la empresa. */
    public function validarPinNuevo(Empresa $empresa, string $pin, ?int $usuarioId = null): void
    {
        if (! preg_match('/^\d{4,8}$/', $pin)) {
            throw new RuntimeException('El PIN debe tener entre 4 y 8 números.');
        }

        $otros = User::where('empresa_id', $empresa->id)->whereNotNull('pin_autorizacion')
            ->when($usuarioId, fn ($q) => $q->whereKeyNot($usuarioId))->pluck('pin_autorizacion');

        foreach ($otros as $hash) {
            if (Hash::check($pin, $hash)) {
                throw new RuntimeException('Ese PIN ya lo usa otro usuario. Elige otro.');
            }
        }
    }
}
