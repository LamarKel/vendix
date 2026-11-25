<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Services\AlertaAntirroboService;
use App\Services\AlertaRecepcionService;
use Illuminate\Console\Command;

class AlertasInventarioCommand extends Command
{
    protected $signature = 'inventario:alertas';

    protected $description = 'Envía las alertas diarias de inventario: mercancía olvidada en recepción y control antirrobo';

    public function handle(AlertaRecepcionService $recepcion, AlertaAntirroboService $antirrobo): int
    {
        Empresa::query()->where('activa', true)->each(function (Empresa $empresa) use ($recepcion, $antirrobo) {
            try {
                $r = $recepcion->notificar($empresa);
                $a = $antirrobo->notificarResumenDiario($empresa);
                $this->line("{$empresa->razon_social}: {$r} en recepción, {$a} alertas de control");
            } catch (\Throwable $e) {
                report($e);
                $this->error("{$empresa->razon_social}: {$e->getMessage()}");
            }
        });

        return self::SUCCESS;
    }
}
