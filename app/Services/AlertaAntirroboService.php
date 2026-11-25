<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EstadoArqueoCaja;
use App\Enums\EstadoVenta;
use App\Enums\OrigenMovimiento;
use App\Enums\TipoComprobante;
use App\Enums\TipoMovimiento;
use App\Enums\TipoNotificacion;
use App\Models\ArqueoCaja;
use App\Models\DetalleRequisicion;
use App\Models\Empresa;
use App\Models\MovimientoInventario;
use App\Models\User;
use App\Models\Venta;
use Carbon\CarbonInterface;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Señales de control (posible robo o error repetido), por persona y por rango de fechas:
 *
 * - Anulaciones de ventas por quien anuló (o vendió, si es una anulación vieja sin ese dato).
 * - Descuentos dados (global + por línea) por vendedor.
 * - Devoluciones de clientes (notas de crédito) por quien las hizo.
 * - Faltantes en el cierre de caja, por cajero.
 * - Salidas de inventario por ajuste o merma, valorizadas, por quien las registró.
 * - Faltantes al recibir requisiciones (despachado que no llegó).
 *
 * Los umbrales son por empresa (Configuración). Una señal no acusa a nadie: dice dónde mirar.
 */
class AlertaAntirroboService
{
    /**
     * Ranking por persona del período.
     *
     * @return Collection<int, array{usuario: string, anulaciones: int, descuentos: float, devoluciones: int, faltante_caja: float, ajustes: float}>
     */
    public function ranking(Empresa $empresa, CarbonInterface $desde, CarbonInterface $hasta): Collection
    {
        $rango = [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()];
        $filas = [];

        $sumar = function (int|string|null $userId, string $campo, float|int $valor) use (&$filas) {
            if ($userId === null) {
                return;
            }
            $filas[$userId] ??= ['anulaciones' => 0, 'descuentos' => 0.0, 'devoluciones' => 0, 'faltante_caja' => 0.0, 'ajustes' => 0.0];
            $filas[$userId][$campo] += $valor;
        };

        Venta::query()->where('empresa_id', $empresa->id)->where('estado', EstadoVenta::ANULADA)
            ->whereBetween('anulada_en', $rango)
            ->selectRaw('COALESCE(anulada_por, user_id) as uid, COUNT(*) as n')->groupByRaw('COALESCE(anulada_por, user_id)')
            ->get()->each(fn ($r) => $sumar($r->uid, 'anulaciones', (int) $r->n));

        $descLinea = DB::table('detalle_ventas')->selectRaw('venta_id, SUM(descuento) as d')->groupBy('venta_id');
        Venta::query()->where('ventas.empresa_id', $empresa->id)->where('ventas.estado', '!=', EstadoVenta::ANULADA)
            ->whereNull('ventas.venta_modificada_id')
            ->whereBetween('ventas.fecha', $rango)
            ->leftJoinSub($descLinea, 'dl', 'dl.venta_id', '=', 'ventas.id')
            ->selectRaw('ventas.user_id as uid, SUM(ventas.descuento + COALESCE(dl.d, 0)) as total')->groupBy('ventas.user_id')
            ->get()->each(fn ($r) => $sumar($r->uid, 'descuentos', (float) $r->total));

        Venta::query()->where('empresa_id', $empresa->id)->whereNotNull('venta_modificada_id')
            ->whereIn('tipo_comprobante', [TipoComprobante::NOTA_CREDITO->value, TipoComprobante::NOTA_CREDITO_FISICA->value])
            ->whereBetween('fecha', $rango)
            ->selectRaw('user_id as uid, COUNT(*) as n')->groupBy('user_id')
            ->get()->each(fn ($r) => $sumar($r->uid, 'devoluciones', (int) $r->n));

        ArqueoCaja::query()->where('empresa_id', $empresa->id)->where('estado', EstadoArqueoCaja::CERRADO)
            ->whereBetween('cerrado_en', $rango)->where('diferencia', '<', 0)
            ->selectRaw('user_id as uid, SUM(diferencia) as total')->groupBy('user_id')
            ->get()->each(fn ($r) => $sumar($r->uid, 'faltante_caja', abs((float) $r->total)));

        MovimientoInventario::query()->where('empresa_id', $empresa->id)
            ->whereIn('origen', [OrigenMovimiento::AJUSTE->value, OrigenMovimiento::MERMA->value])
            ->whereBetween('created_at', $rango)
            ->where(fn ($q) => $q->where('tipo', TipoMovimiento::SALIDA->value)
                ->orWhere(fn ($w) => $w->where('tipo', TipoMovimiento::AJUSTE->value)->where('cantidad', '<', 0)))
            ->selectRaw('user_id as uid, SUM(ABS(cantidad) * costo_unitario) as total')->groupBy('user_id')
            ->get()->each(fn ($r) => $sumar($r->uid, 'ajustes', (float) $r->total));

        $nombres = User::whereIn('id', array_keys($filas))->pluck('name', 'id');

        return collect($filas)
            ->map(fn (array $f, $uid) => ['usuario' => $nombres[$uid] ?? "Usuario #{$uid}", ...$f])
            ->sortByDesc(fn ($f) => $f['anulaciones'] * 1000 + $f['devoluciones'] * 1000 + $f['faltante_caja'] + $f['ajustes'] + $f['descuentos'])
            ->values();
    }

    /**
     * Señales que pasan el umbral en el período, listas para mostrar.
     *
     * @return array<int, array{color: string, titulo: string, detalle: string}>
     */
    public function senales(Empresa $empresa, CarbonInterface $desde, CarbonInterface $hasta): array
    {
        $c = $empresa->config();
        $ranking = $this->ranking($empresa, $desde, $hasta);
        $senales = [];

        $agregar = function (string $campo, float $umbral, string $titulo, \Closure $formato) use ($ranking, &$senales) {
            $sobre = $ranking->filter(fn ($f) => $f[$campo] > 0 && $f[$campo] >= $umbral);
            if ($sobre->isNotEmpty()) {
                $senales[] = ['color' => 'rojo', 'titulo' => $titulo, 'detalle' => $sobre->take(5)->map(fn ($f) => "{$f['usuario']}: ".$formato($f[$campo]))->implode(' · ')];
            }
        };

        $dinero = fn ($v) => 'RD$ '.number_format((float) $v, 2);
        $agregar('anulaciones', (float) $c->antirrobo_umbral_anulaciones, 'Muchas anulaciones de ventas', fn ($v) => "{$v} anuladas");
        $agregar('devoluciones', (float) $c->antirrobo_umbral_devoluciones, 'Muchas devoluciones de clientes', fn ($v) => "{$v} devoluciones");
        $agregar('descuentos', (float) $c->antirrobo_umbral_descuentos, 'Descuentos altos', $dinero);
        $agregar('faltante_caja', 0.01, 'Faltantes en cierre de caja', $dinero);
        $agregar('ajustes', (float) $c->antirrobo_umbral_ajustes, 'Salidas por ajuste o merma', $dinero);

        $faltantes = DetalleRequisicion::query()
            ->whereHas('requisicion', fn ($q) => $q->where('empresa_id', $empresa->id)
                ->whereBetween('recibido_en', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()]))
            ->whereColumn('cantidad_recibida', '<', 'cantidad_despachada')
            ->with(['producto:id,nombre', 'requisicion:id,numero,tipo'])
            ->get();

        if ($faltantes->isNotEmpty()) {
            $senales[] = [
                'color' => 'naranja',
                'titulo' => 'Faltantes al recibir requisiciones',
                'detalle' => $faltantes->take(5)->map(fn ($d) => "{$d->requisicion->etiquetaNumero()} {$d->producto->nombre}: faltaron "
                    .rtrim(rtrim(number_format((float) $d->faltante(), 3, '.', ''), '0'), '.'))->implode(' · '),
            ];
        }

        $conteos = \App\Models\Conteo::query()
            ->where('empresa_id', $empresa->id)
            ->where('estado', \App\Enums\EstadoConteo::APROBADO->value)
            ->whereBetween('aprobado_en', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->where('valor_diferencia', '<', 0)
            ->with('localidad.sucursal')
            ->get();

        if ($conteos->isNotEmpty()) {
            $senales[] = [
                'color' => 'rojo',
                'titulo' => 'Faltantes en conteos físicos',
                'detalle' => $conteos->take(5)->map(fn ($c) => "{$c->etiquetaNumero()} {$c->localidad->nombreCompleto()}: RD$ "
                    .number_format(abs((float) $c->valor_diferencia), 2))->implode(' · '),
            ];
        }

        return $senales;
    }

    /** @return array<int, array{color: string, titulo: string, detalle: string}> */
    public function alertasDashboard(Empresa $empresa): array
    {
        return $this->senales($empresa, now(), now());
    }

    /** Resumen de AYER a quien tenga el permiso de alertas de control. Devuelve cuántas señales hubo. */
    public function notificarResumenDiario(Empresa $empresa): int
    {
        $ayer = now()->subDay();
        $senales = $this->senales($empresa, $ayer, $ayer);

        if ($senales === []) {
            return 0;
        }

        app(NotificacionService::class)->enviar(
            TipoNotificacion::ANTIRROBO,
            $empresa,
            Notification::make()
                ->title('Control del '.$ayer->format('d/m/Y').': '.count($senales).' alerta(s)')
                ->body(collect($senales)->map(fn ($s) => "{$s['titulo']}: {$s['detalle']}")->implode("\n"))
                ->danger(),
        );

        return count($senales);
    }
}
