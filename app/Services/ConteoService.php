<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EstadoConteo;
use App\Enums\EstadoVenta;
use App\Enums\OrigenMovimiento;
use App\Enums\TipoConteo;
use App\Enums\TipoLocalidad;
use App\Enums\TipoMovimiento;
use App\Models\Conteo;
use App\Models\DetalleConteo;
use App\Models\Empresa;
use App\Models\Localidad;
use App\Models\Producto;
use App\Models\StockLocalidad;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Conteos físicos por localidad.
 *
 * - Crear: encargado (conteos.crear) de la sucursal de la localidad. Asigna a un contador.
 * - Contar: SOLO el asignado (o quien administra sucursales), a ciegas. Al registrar cada línea se
 *   fija la existencia del sistema en ese instante (existencia_al_contar) y su costo.
 * - Cerrar: todas las líneas contadas → en revisión.
 * - Reconteo: el encargado devuelve líneas a "contando" (puede reasignar a otra persona).
 * - Aprobar: conteos.aprobar; si el valor absoluto de las diferencias pasa el umbral de la
 *   empresa, solo quien administra sucursales. Cada diferencia se ajusta en la localidad como
 *   movimiento CONTEO, aplicado sobre la existencia ACTUAL (respeta lo vendido después de contar).
 * Cada paso bloquea el conteo (lockForUpdate).
 */
class ConteoService
{
    public function __construct(
        private readonly InventarioService $inventario,
        private readonly LocalidadService $localidades,
        private readonly RequisicionService $requisiciones,
    ) {}

    /**
     * @param  array<int>  $productoIds  para tipo selección o cíclico (el cíclico lo arma sugerirCiclico())
     */
    public function crear(Empresa $empresa, User $creador, int $localidadId, TipoConteo $tipo, int $asignadoA, array $productoIds = [], ?string $notas = null): Conteo
    {
        if (! $creador->can('conteos.crear')) {
            throw new RuntimeException('No tienes permiso para crear conteos.');
        }

        $localidad = $this->localidades->localidadDeEmpresa($empresa, $localidadId);
        $this->exigirSucursal($creador, $localidad);

        $asignado = User::where('empresa_id', $empresa->id)->find($asignadoA);
        if ($asignado === null) {
            throw new RuntimeException('El contador asignado no pertenece a la empresa.');
        }

        if (Conteo::where('localidad_id', $localidad->id)->whereIn('estado', [EstadoConteo::EN_CONTEO->value, EstadoConteo::EN_REVISION->value])->exists()) {
            throw new RuntimeException("Ya hay un conteo abierto en {$localidad->nombre}. Termínalo o cancélalo antes de crear otro.");
        }

        $productos = match ($tipo) {
            TipoConteo::TOTAL => Producto::where('empresa_id', $empresa->id)->where('activo', true)->where('controla_stock', true)->pluck('id')->all(),
            default => Producto::where('empresa_id', $empresa->id)->where('controla_stock', true)->whereIn('id', $productoIds)->pluck('id')->all(),
        };

        if ($productos === []) {
            throw new RuntimeException('El conteo no tiene productos.');
        }

        if (count($productos) > 5000) {
            throw new RuntimeException('Máximo 5,000 productos por conteo: divídelo por categoría.');
        }

        return DB::transaction(function () use ($empresa, $creador, $localidad, $tipo, $asignado, $productos, $notas) {
            Empresa::whereKey($empresa->id)->lockForUpdate()->first();

            $conteo = Conteo::create([
                'empresa_id' => $empresa->id,
                'numero' => (int) Conteo::where('empresa_id', $empresa->id)->max('numero') + 1,
                'localidad_id' => $localidad->id,
                'sucursal_id' => $localidad->sucursal_id,
                'tipo' => $tipo,
                'estado' => EstadoConteo::EN_CONTEO,
                'asignado_a' => $asignado->id,
                'creado_por' => $creador->id,
                'notas' => filled($notas) ? mb_substr(trim($notas), 0, 500) : null,
            ]);

            $ahora = now();
            foreach (array_chunk($productos, 500) as $lote) {
                DetalleConteo::insert(array_map(fn ($id) => [
                    'conteo_id' => $conteo->id, 'producto_id' => $id, 'veces_contado' => 0,
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ], $lote));
            }

            return $conteo;
        });
    }

    /**
     * Registra cantidades contadas (a ciegas). Puede llamarse varias veces mientras está "contando".
     *
     * @param  array<int, string|float|int|null>  $cantidades  detalle_id => cantidad (null/'' = sin contar)
     */
    public function registrar(Conteo $conteo, Empresa $empresa, User $contador, array $cantidades): Conteo
    {
        return DB::transaction(function () use ($conteo, $empresa, $contador, $cantidades) {
            $c = $this->bloquear($conteo, $empresa);
            $this->exigirEstado($c, EstadoConteo::EN_CONTEO);

            if ((int) $c->asignado_a !== (int) $contador->id && ! $contador->can('sucursales.administrar')) {
                throw new RuntimeException('Este conteo está asignado a otra persona.');
            }

            $detalles = $c->detalles()->whereIn('id', array_keys($cantidades))->with('producto')->get()->keyBy('id');

            foreach ($cantidades as $detalleId => $valor) {
                $detalle = $detalles->get((int) $detalleId);

                if ($detalle === null || $valor === null || trim((string) $valor) === '') {
                    continue;
                }

                $valor = trim((string) $valor);
                if (! is_numeric($valor) || bccomp($valor, '0', 3) < 0 || bccomp($valor, '99999999', 3) > 0) {
                    throw new RuntimeException("Cantidad inválida para «{$detalle->producto->nombre}».");
                }

                $existencia = (string) (StockLocalidad::where('localidad_id', $c->localidad_id)
                    ->where('producto_id', $detalle->producto_id)
                    ->lockForUpdate()
                    ->value('cantidad') ?? '0');

                $detalle->update([
                    'cantidad_contada' => bcadd($valor, '0', 3),
                    'existencia_al_contar' => $existencia,
                    'costo_unitario' => (string) $detalle->producto->costo,
                    'contado_por' => $contador->id,
                    'contado_en' => now(),
                    'veces_contado' => $detalle->veces_contado + 1,
                ]);
            }

            return $c;
        });
    }

    public function cerrar(Conteo $conteo, Empresa $empresa, User $contador): Conteo
    {
        return DB::transaction(function () use ($conteo, $empresa, $contador) {
            $c = $this->bloquear($conteo, $empresa);
            $this->exigirEstado($c, EstadoConteo::EN_CONTEO);

            if ((int) $c->asignado_a !== (int) $contador->id && ! $contador->can('sucursales.administrar')) {
                throw new RuntimeException('Este conteo está asignado a otra persona.');
            }

            $faltan = $c->detalles()->whereNull('cantidad_contada')->count();
            if ($faltan > 0) {
                throw new RuntimeException("Faltan {$faltan} producto(s) por contar. Si no hay, pon 0.");
            }

            $c->update(['estado' => EstadoConteo::EN_REVISION, 'cerrado_en' => now(), 'valor_diferencia' => $this->valorTotal($c)]);

            return $c;
        });
    }

    /** @param  array<int>  $detalleIds */
    public function pedirReconteo(Conteo $conteo, Empresa $empresa, User $encargado, array $detalleIds, ?int $reasignarA = null): Conteo
    {
        if (! $encargado->can('conteos.aprobar')) {
            throw new RuntimeException('No tienes permiso para pedir reconteos.');
        }

        return DB::transaction(function () use ($conteo, $empresa, $encargado, $detalleIds, $reasignarA) {
            $c = $this->bloquear($conteo, $empresa);
            $this->exigirEstado($c, EstadoConteo::EN_REVISION);
            $this->exigirSucursal($encargado, $c->localidad);

            $n = $c->detalles()->whereIn('id', $detalleIds)->update(['cantidad_contada' => null, 'existencia_al_contar' => null, 'contado_por' => null, 'contado_en' => null]);
            if ($n === 0) {
                throw new RuntimeException('Elige al menos un producto para recontar.');
            }

            $datos = ['estado' => EstadoConteo::EN_CONTEO, 'cerrado_en' => null, 'valor_diferencia' => null];
            if ($reasignarA !== null) {
                if (! User::where('empresa_id', $empresa->id)->whereKey($reasignarA)->exists()) {
                    throw new RuntimeException('El contador no pertenece a la empresa.');
                }
                $datos['asignado_a'] = $reasignarA;
            }
            $c->update($datos);

            return $c;
        });
    }

    public function aprobar(Conteo $conteo, Empresa $empresa, User $aprobador): Conteo
    {
        if (! $aprobador->can('conteos.aprobar')) {
            throw new RuntimeException('No tienes permiso para aprobar conteos.');
        }

        return DB::transaction(function () use ($conteo, $empresa, $aprobador) {
            $c = $this->bloquear($conteo, $empresa);
            $this->exigirEstado($c, EstadoConteo::EN_REVISION);
            $this->exigirSucursal($aprobador, $c->localidad);

            $valorAbsoluto = '0';
            foreach ($c->detalles as $d) {
                $v = $d->valorDiferencia() ?? '0';
                $valorAbsoluto = bcadd($valorAbsoluto, bccomp($v, '0', 2) < 0 ? bcsub('0', $v, 2) : $v, 2);
            }

            $umbral = (string) $empresa->config()->conteo_umbral_aprobacion_admin;
            if (bccomp($valorAbsoluto, $umbral, 2) > 0 && ! $aprobador->can('sucursales.administrar')) {
                throw new RuntimeException('Las diferencias suman RD$ '.number_format((float) $valorAbsoluto, 2).': este conteo lo debe aprobar el administrador.');
            }

            $esVenta = $c->localidad->tipo === TipoLocalidad::VENTA;

            foreach ($c->detalles as $d) {
                $diferencia = $d->diferencia();
                if ($diferencia === null || bccomp($diferencia, '0', 3) === 0) {
                    continue;
                }

                $this->inventario->registrarMovimiento(
                    $d->producto, TipoMovimiento::AJUSTE, OrigenMovimiento::CONTEO, $diferencia,
                    $c->id, $aprobador->id, "{$c->etiquetaNumero()}: contado {$d->cantidad_contada}, sistema {$d->existencia_al_contar}",
                    // En la tienda, lo vendido después de contar pudo dejarla en menos que la diferencia.
                    permitirNegativo: $esVenta,
                    costoUnitario: (string) $d->costo_unitario,
                    localidad: $c->localidad,
                );
            }

            $c->update([
                'estado' => EstadoConteo::APROBADO,
                'aprobado_por' => $aprobador->id,
                'aprobado_en' => now(),
                'valor_diferencia' => $this->valorTotal($c),
            ]);

            return $c;
        });
    }

    public function cancelar(Conteo $conteo, Empresa $empresa, User $usuario, string $motivo): Conteo
    {
        if (! $usuario->can('conteos.crear')) {
            throw new RuntimeException('No tienes permiso para cancelar conteos.');
        }

        if (trim($motivo) === '') {
            throw new RuntimeException('Indica el motivo.');
        }

        return DB::transaction(function () use ($conteo, $empresa, $usuario, $motivo) {
            $c = $this->bloquear($conteo, $empresa);
            $this->exigirSucursal($usuario, $c->localidad);

            if (! in_array($c->estado, [EstadoConteo::EN_CONTEO, EstadoConteo::EN_REVISION], true)) {
                throw new RuntimeException('Este conteo ya no se puede cancelar.');
            }

            $c->update(['estado' => EstadoConteo::CANCELADO, 'motivo_cancelacion' => mb_substr(trim($motivo), 0, 255)]);

            return $c;
        });
    }

    /**
     * Propuesta del conteo cíclico: $cantidad productos con existencia en la localidad, priorizando
     * (1) los que tuvieron diferencias en conteos anteriores, (2) los de más valor vendido en 90
     * días, (3) los de más valor en existencia; y saltando los contados en los últimos 30 días.
     *
     * @return array<int>
     */
    public function sugerirCiclico(Empresa $empresa, Localidad $localidad, int $cantidad = 15): array
    {
        $recientes = DetalleConteo::query()
            ->join('conteos', 'conteos.id', '=', 'detalle_conteos.conteo_id')
            ->where('conteos.localidad_id', $localidad->id)
            ->where('detalle_conteos.contado_en', '>=', now()->subDays(30))
            ->pluck('detalle_conteos.producto_id');

        $conDiferencias = DetalleConteo::query()
            ->join('conteos', 'conteos.id', '=', 'detalle_conteos.conteo_id')
            ->where('conteos.localidad_id', $localidad->id)
            ->where('conteos.estado', EstadoConteo::APROBADO->value)
            ->whereColumn('detalle_conteos.cantidad_contada', '!=', 'detalle_conteos.existencia_al_contar')
            ->selectRaw('detalle_conteos.producto_id, COUNT(*) as veces')
            ->groupBy('detalle_conteos.producto_id')
            ->pluck('veces', 'producto_id');

        $vendido = DB::table('detalle_ventas')
            ->join('ventas', 'ventas.id', '=', 'detalle_ventas.venta_id')
            ->where('ventas.empresa_id', $empresa->id)
            ->where('ventas.estado', '!=', EstadoVenta::ANULADA->value)
            ->where('ventas.fecha', '>=', now()->subDays(90))
            ->selectRaw('detalle_ventas.producto_id, SUM(detalle_ventas.subtotal) as valor')
            ->groupBy('detalle_ventas.producto_id')
            ->pluck('valor', 'producto_id');

        return StockLocalidad::query()
            ->where('localidad_id', $localidad->id)
            ->where('cantidad', '!=', 0)
            ->whereNotIn('producto_id', $recientes)
            ->whereHas('producto', fn ($q) => $q->where('activo', true)->where('controla_stock', true))
            ->with('producto:id,costo')
            ->get()
            ->sortByDesc(fn (StockLocalidad $s) => [
                (int) ($conDiferencias[$s->producto_id] ?? 0),
                (float) ($vendido[$s->producto_id] ?? 0),
                abs((float) $s->cantidad) * (float) $s->producto->costo,
            ])
            ->take(max(1, min($cantidad, 200)))
            ->pluck('producto_id')
            ->values()
            ->all();
    }

    // ──────────────────────────────────────────────────────────────────────

    private function valorTotal(Conteo $c): string
    {
        return $c->detalles()->get()->reduce(fn (string $t, DetalleConteo $d) => bcadd($t, $d->valorDiferencia() ?? '0', 2), '0.00');
    }

    private function bloquear(Conteo $conteo, Empresa $empresa): Conteo
    {
        $c = Conteo::where('empresa_id', $empresa->id)->lockForUpdate()->find($conteo->id);

        if ($c === null) {
            throw new RuntimeException('El conteo no existe.');
        }

        return $c->load(['detalles.producto', 'localidad']);
    }

    private function exigirEstado(Conteo $c, EstadoConteo $estado): void
    {
        if ($c->estado !== $estado) {
            throw new RuntimeException("El conteo {$c->etiquetaNumero()} está «{$c->estado->etiqueta()}»: no se puede hacer esto ahora.");
        }
    }

    private function exigirSucursal(User $usuario, Localidad $localidad): void
    {
        if (! $this->requisiciones->esDeSuSucursal($usuario, $localidad)) {
            throw new RuntimeException('Esa localidad pertenece a otra sucursal.');
        }
    }
}
