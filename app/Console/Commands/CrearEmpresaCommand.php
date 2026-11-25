<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PerfilEmpresa;
use App\Models\Empresa;
use App\Services\ModulosEmpresaService;
use App\Services\RolesEmpresaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Crea una empresa desde consola con la misma configuración inicial que EmpresaResource
 * (roles base, módulos del perfil y, en lavandería, ventas sin comprobante).
 *
 * Hace falta en una instalación limpia: el panel es multi-empresa y no tiene auto-registro,
 * así que sin al menos una empresa el super-admin no tiene a dónde entrar después del login.
 */
class CrearEmpresaCommand extends Command
{
    protected $signature = 'erp:crear-empresa
        {--razon-social= : Razón social}
        {--nombre-comercial= : Nombre comercial (por defecto, la razón social)}
        {--rnc= : RNC (opcional)}
        {--perfil= : facturacion o retail}';

    protected $description = 'Crea una empresa con sus roles y módulos base (necesario en una instalación nueva)';

    public function handle(RolesEmpresaService $roles, ModulosEmpresaService $modulos): int
    {
        $razonSocial = trim((string) ($this->option('razon-social') ?: $this->ask('Razón social')));

        if ($razonSocial === '') {
            $this->error('La razón social es obligatoria.');

            return self::FAILURE;
        }

        $nombreComercial = $this->option('nombre-comercial');
        if (blank($nombreComercial) && $this->option('perfil') === null) {
            $nombreComercial = $this->ask('Nombre comercial', $razonSocial);
        }
        $nombreComercial = trim((string) ($nombreComercial ?: $razonSocial));

        $rnc = $this->option('rnc');
        if ($rnc === null && $this->input->isInteractive()) {
            $rnc = $this->ask('RNC (Enter para dejarlo vacío)');
        }
        $rnc = filled($rnc) ? preg_replace('/\D/', '', (string) $rnc) : null;

        $perfilValor = $this->option('perfil') ?: $this->choice(
            'Tipo de negocio',
            array_map(fn (PerfilEmpresa $p) => $p->value, PerfilEmpresa::cases()),
            PerfilEmpresa::RETAIL->value,
        );

        $perfil = PerfilEmpresa::tryFrom((string) $perfilValor);
        if ($perfil === null) {
            $this->error("Perfil no válido: {$perfilValor}");

            return self::FAILURE;
        }

        if ($rnc !== null && Empresa::where('rnc', $rnc)->exists()) {
            $this->error("Ya existe una empresa con el RNC {$rnc}.");

            return self::FAILURE;
        }

        $empresa = DB::transaction(function () use ($razonSocial, $nombreComercial, $rnc, $perfil, $roles, $modulos) {
            $empresa = Empresa::create([
                'razon_social' => $razonSocial,
                'nombre_comercial' => $nombreComercial,
                'rnc' => $rnc,
                'perfil' => $perfil,
                'activa' => true,
                'usa_ecf' => false,
            ]);

            $roles->sembrarRolesBase($empresa);
            $modulos->sembrarModulos($empresa);

            return $empresa;
        });

        $this->info("Empresa creada: {$empresa->razon_social} ({$perfil->etiqueta()})");
        $this->line("URL: /admin/{$empresa->slug}");

        return self::SUCCESS;
    }
}
