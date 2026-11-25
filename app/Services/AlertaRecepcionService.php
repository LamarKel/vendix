<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TipoLocalidad;
use App\Enums\TipoNotificacion;
use App\Models\Empresa;
use App\Models\StockLocalidad;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Mercancía olvidada en Recepción: existencia en una localidad de recepción que nadie ha tocado en
 * más de N días (config dias_alerta_recepcion). La fila de stock_localidades se actualiza con cada
 * entrada o salida, así que su updated_at es "la última vez que alguien movió ese producto ahí".
 */
class AlertaRecepcionService
{
    /** @return Collection<int, StockLocalidad> */
    public function olvidada(Empresa $empresa, ?int $sucursalId = null): Collection
    {
        $dias = max(1, (int) $empresa->config()->dias_alerta_recepcion);

        return StockLocalidad::query()
            ->where('stock_localidades.empresa_id', $empresa->id)
            ->where('stock_localidades.cantidad', '>', 0)
            ->where('stock_localidades.updated_at', '<', now()->subDays($dias))
            ->whereHas('localidad', fn ($q) => $q
                ->where('tipo', TipoLocalidad::RECEPCION->value)
                ->when($sucursalId, fn ($w) => $w->where('sucursal_id', $sucursalId)))
            ->with(['localidad.sucursal', 'producto:id,nombre'])
            ->orderBy('stock_localidades.updated_at')
            ->get();
    }

    public function resumen(Collection $filas): string
    {
        $nombres = $filas->take(3)->map(fn (StockLocalidad $f) => "{$f->producto->nombre} ({$f->localidad->sucursal->nombre}, "
            .rtrim(rtrim(number_format((float) $f->cantidad, 3, '.', ''), '0'), '.').', '.$f->updated_at->diffInDays().' días)')->implode(', ');
        $restantes = $filas->count() - 3;

        return $nombres.($restantes > 0 ? " y {$restantes} más" : '').'. Ubícalos con un movimiento interno.';
    }

    /** Envía la alerta del día si hay algo olvidado. Devuelve cuántas filas encontró. */
    public function notificar(Empresa $empresa): int
    {
        $filas = $this->olvidada($empresa);

        if ($filas->isEmpty()) {
            return 0;
        }

        app(NotificacionService::class)->enviar(
            TipoNotificacion::RECEPCION_PENDIENTE,
            $empresa,
            Notification::make()
                ->title("Mercancía en recepción sin ubicar ({$filas->count()})")
                ->body($this->resumen($filas))
                ->warning(),
        );

        return $filas->count();
    }
}
