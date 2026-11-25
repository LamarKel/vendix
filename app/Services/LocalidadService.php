<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TipoLocalidad;
use App\Models\Caja;
use App\Models\Empresa;
use App\Models\Localidad;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Estructura sucursal → localidades y a qué localidad le toca cada movimiento.
 *
 * - Toda empresa tiene al menos una sucursal (la principal) y cada sucursal una localidad de venta
 *   y una de recepción. Se crean solas al crear la empresa o la sucursal.
 * - La sucursal de una operación sale del usuario, de la caja o, si no tienen, de la principal.
 */
class LocalidadService
{
    /** Crea la sucursal principal con sus localidades si la empresa no tiene ninguna. Idempotente. */
    public function asegurarEstructura(Empresa $empresa): Sucursal
    {
        return DB::transaction(function () use ($empresa) {
            $principal = Sucursal::where('empresa_id', $empresa->id)->orderByDesc('es_principal')->orderBy('id')->first();

            if ($principal === null) {
                $principal = Sucursal::create([
                    'empresa_id' => $empresa->id,
                    'nombre' => 'Principal',
                    'es_principal' => true,
                ]);
            }

            $this->asegurarLocalidadesBase($principal);

            return $principal;
        });
    }

    /** Toda sucursal nueva arranca con Tienda (venta), Recibo (recepción) y Almacén. */
    public function asegurarLocalidadesBase(Sucursal $sucursal): void
    {
        foreach ([TipoLocalidad::VENTA->value => 'Tienda', TipoLocalidad::RECEPCION->value => 'Recibo'] as $tipo => $nombre) {
            if (! Localidad::where('sucursal_id', $sucursal->id)->where('tipo', $tipo)->where('activa', true)->exists()) {
                Localidad::firstOrCreate(
                    ['sucursal_id' => $sucursal->id, 'nombre' => $nombre],
                    ['empresa_id' => $sucursal->empresa_id, 'tipo' => $tipo, 'activa' => true],
                );
            }
        }

        if (! Localidad::where('sucursal_id', $sucursal->id)->where('tipo', TipoLocalidad::ALMACENAJE->value)->exists()) {
            Localidad::firstOrCreate(
                ['sucursal_id' => $sucursal->id, 'nombre' => 'Almacén'],
                ['empresa_id' => $sucursal->empresa_id, 'tipo' => TipoLocalidad::ALMACENAJE->value, 'activa' => true],
            );
        }
    }

    public function principal(Empresa $empresa): Sucursal
    {
        return Sucursal::where('empresa_id', $empresa->id)->where('activa', true)->orderByDesc('es_principal')->orderBy('id')->first()
            ?? $this->asegurarEstructura($empresa);
    }

    /** Sucursal en la que opera un usuario (o la caja, si se indica), validada contra la empresa. */
    public function sucursalDe(Empresa $empresa, ?User $usuario = null, ?Caja $caja = null): Sucursal
    {
        $id = $caja?->sucursal_id ?? $usuario?->sucursal_id;

        if ($id !== null) {
            $sucursal = Sucursal::where('empresa_id', $empresa->id)->where('activa', true)->find($id);

            if ($sucursal !== null) {
                return $sucursal;
            }
        }

        return $this->principal($empresa);
    }

    public function localidadDeVenta(Sucursal $sucursal): Localidad
    {
        return $this->deTipo($sucursal, TipoLocalidad::VENTA);
    }

    public function localidadDeRecepcion(Sucursal $sucursal): Localidad
    {
        return $this->deTipo($sucursal, TipoLocalidad::RECEPCION);
    }

    private function deTipo(Sucursal $sucursal, TipoLocalidad $tipo): Localidad
    {
        $localidad = $sucursal->localidadDeTipo($tipo);

        if ($localidad === null) {
            $this->asegurarLocalidadesBase($sucursal);
            $localidad = $sucursal->localidadDeTipo($tipo);
        }

        if ($localidad === null) {
            throw new RuntimeException("La sucursal {$sucursal->nombre} no tiene localidad de {$tipo->corta()} activa.");
        }

        return $localidad;
    }

    /** Localidad de la empresa (revalidada: el id puede venir de un formulario). */
    public function localidadDeEmpresa(Empresa $empresa, int $localidadId): Localidad
    {
        $localidad = Localidad::where('empresa_id', $empresa->id)->where('activa', true)->with('sucursal')->find($localidadId);

        if ($localidad === null) {
            throw new RuntimeException('La localidad elegida no existe o está inactiva.');
        }

        return $localidad;
    }
}
