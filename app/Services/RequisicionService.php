<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EstadoRequisicion;
use App\Enums\OrigenMovimiento;
use App\Enums\TipoMovimiento;
use App\Models\DetalleRequisicion;
use App\Models\Empresa;
use App\Models\Localidad;
use App\Models\Producto;
use App\Models\Requisicion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Requisiciones y movimientos internos entre localidades.
 *
 * Reglas de sucursal (nadie toca lo ajeno):
 * - Crear: la localidad DESTINO (la que pide) debe ser de la sucursal del usuario.
 * - Despachar: la localidad ORIGEN (la que surte) debe ser de la sucursal del usuario.
 * - Recibir: la localidad DESTINO debe ser de la sucursal del usuario.
 * - Movimiento interno: origen y destino de la misma sucursal, la del usuario.
 * - Quien administra sucursales (sucursales.administrar) puede operar cualquiera.
 *
 * Stock: despachar saca del origen (nunca deja el origen en negativo); recibir entra al destino
 * lo que de verdad llegó. Lo despachado y no recibido queda registrado como faltante.
 * Cada paso bloquea la requisición (lockForUpdate): dos personas no despachan/reciben dos veces.
 */
class RequisicionService
{
    public function __construct(
        private readonly InventarioService $inventario,
        private readonly LocalidadService $localidades,
    ) {}

    /**
     * @param  array<int, array{producto_id: int, cantidad: float|int|string}>  $lineas
     */
    public function crear(Empresa $empresa, User $usuario, int $origenId, int $destinoId, array $lineas, ?string $notas = null): Requisicion
    {
        $this->exigirPermiso($usuario, 'requisiciones.crear', 'crear requisiciones');
        [$origen, $destino] = $this->validarLocalidades($empresa, $origenId, $destinoId);
        $this->exigirSucursal($usuario, $destino, 'pedir para esta localidad');

        $entreSucursales = $origen->sucursal_id !== $destino->sucursal_id;
        $requiereAprobacion = $entreSucursales && $empresa->config()->requisicion_sucursal_requiere_aprobacion;

        $detalles = $this->validarLineas($empresa, $lineas);

        return DB::transaction(function () use ($empresa, $usuario, $origen, $destino, $entreSucursales, $requiereAprobacion, $detalles, $notas) {
            $requisicion = Requisicion::create([
                'empresa_id' => $empresa->id,
                'numero' => $this->siguienteNumero($empresa),
                'tipo' => Requisicion::TIPO_REQUISICION,
                'estado' => $requiereAprobacion ? EstadoRequisicion::PENDIENTE_APROBACION : EstadoRequisicion::PENDIENTE,
                'localidad_origen_id' => $origen->id,
                'localidad_destino_id' => $destino->id,
                'sucursal_origen_id' => $origen->sucursal_id,
                'sucursal_destino_id' => $destino->sucursal_id,
                'entre_sucursales' => $entreSucursales,
                'notas' => filled($notas) ? mb_substr(trim($notas), 0, 500) : null,
                'solicitado_por' => $usuario->id,
            ]);

            $requisicion->detalles()->createMany(array_map(fn ($d) => [
                'producto_id' => $d['producto_id'],
                'cantidad_solicitada' => $d['cantidad'],
            ], $detalles));

            return $requisicion->load('detalles');
        });
    }

    public function aprobar(Requisicion $requisicion, Empresa $empresa, User $usuario): Requisicion
    {
        if (! $usuario->can('requisiciones.aprobar')) {
            throw new RuntimeException('No tienes permiso para aprobar requisiciones.');
        }

        return DB::transaction(function () use ($requisicion, $empresa, $usuario) {
            $r = $this->bloquear($requisicion, $empresa);
            $this->exigirEstado($r, EstadoRequisicion::PENDIENTE_APROBACION);

            $r->update(['estado' => EstadoRequisicion::PENDIENTE, 'aprobado_por' => $usuario->id, 'aprobado_en' => now()]);

            return $r;
        });
    }

    public function rechazar(Requisicion $requisicion, Empresa $empresa, User $usuario, string $motivo): Requisicion
    {
        if (! $usuario->can('requisiciones.aprobar')) {
            throw new RuntimeException('No tienes permiso para rechazar requisiciones.');
        }

        return $this->cerrarSinMovimiento($requisicion, $empresa, $usuario, $motivo, EstadoRequisicion::RECHAZADA, [EstadoRequisicion::PENDIENTE_APROBACION]);
    }

    /**
     * Despacha: sale del origen lo indicado por línea (puede ser menos de lo pedido, o 0).
     *
     * @param  array<int, string|float|int>  $cantidades  detalle_id => cantidad despachada
     * @param  array<int, string|null>  $notas  detalle_id => nota (obligatoria si se despacha menos)
     */
    public function despachar(Requisicion $requisicion, Empresa $empresa, User $usuario, array $cantidades, array $notas = []): Requisicion
    {
        $this->exigirPermiso($usuario, 'requisiciones.despachar', 'despachar requisiciones');

        return DB::transaction(function () use ($requisicion, $empresa, $usuario, $cantidades, $notas) {
            $r = $this->bloquear($requisicion, $empresa);
            $this->exigirEstado($r, EstadoRequisicion::PENDIENTE);
            $this->exigirSucursal($usuario, $r->localidadOrigen, 'despachar desde esta localidad');

            $algo = false;

            foreach ($r->detalles as $detalle) {
                $cantidad = $this->cantidadValida($cantidades[$detalle->id] ?? $detalle->cantidad_solicitada, (string) $detalle->cantidad_solicitada);
                $nota = filled($notas[$detalle->id] ?? null) ? mb_substr(trim((string) $notas[$detalle->id]), 0, 255) : null;

                if (bccomp($cantidad, (string) $detalle->cantidad_solicitada, 3) < 0 && $nota === null) {
                    throw new RuntimeException("Indica por qué se despacha menos de lo pedido de «{$detalle->producto->nombre}».");
                }

                if (bccomp($cantidad, '0', 3) > 0) {
                    $algo = true;
                    $this->inventario->registrarMovimiento(
                        $detalle->producto, TipoMovimiento::SALIDA, OrigenMovimiento::REQUISICION_DESPACHO, $cantidad,
                        $r->id, $usuario->id, "{$r->etiquetaNumero()} → {$r->localidadDestino->nombreCompleto()}",
                        localidad: $r->localidadOrigen,
                    );
                }

                $detalle->update(['cantidad_despachada' => $cantidad, 'nota_despacho' => $nota]);
            }

            if (! $algo) {
                throw new RuntimeException('No se despachó nada. Si no se puede surtir, cancela la requisición.');
            }

            $r->update(['estado' => EstadoRequisicion::DESPACHADA, 'despachado_por' => $usuario->id, 'despachado_en' => now()]);

            return $r->load('detalles');
        });
    }

    /**
     * Recibe: entra al destino lo que llegó por línea (nunca más de lo despachado). La diferencia
     * queda como faltante con nota obligatoria.
     *
     * @param  array<int, string|float|int>  $cantidades  detalle_id => cantidad recibida
     * @param  array<int, string|null>  $notas
     */
    public function recibir(Requisicion $requisicion, Empresa $empresa, User $usuario, array $cantidades = [], array $notas = []): Requisicion
    {
        $this->exigirPermiso($usuario, 'requisiciones.recibir', 'recibir requisiciones');

        return DB::transaction(function () use ($requisicion, $empresa, $usuario, $cantidades, $notas) {
            $r = $this->bloquear($requisicion, $empresa);
            $this->exigirEstado($r, EstadoRequisicion::DESPACHADA);
            $this->exigirSucursal($usuario, $r->localidadDestino, 'recibir en esta localidad');

            foreach ($r->detalles as $detalle) {
                $despachado = (string) ($detalle->cantidad_despachada ?? '0');
                $cantidad = $this->cantidadValida($cantidades[$detalle->id] ?? $despachado, $despachado);
                $nota = filled($notas[$detalle->id] ?? null) ? mb_substr(trim((string) $notas[$detalle->id]), 0, 255) : null;

                if (bccomp($cantidad, $despachado, 3) < 0 && $nota === null) {
                    throw new RuntimeException("Indica qué pasó con lo que faltó de «{$detalle->producto->nombre}».");
                }

                if (bccomp($cantidad, '0', 3) > 0) {
                    $this->inventario->registrarMovimiento(
                        $detalle->producto, TipoMovimiento::ENTRADA, OrigenMovimiento::REQUISICION_RECEPCION, $cantidad,
                        $r->id, $usuario->id, "{$r->etiquetaNumero()} ← {$r->localidadOrigen->nombreCompleto()}",
                        localidad: $r->localidadDestino,
                    );
                }

                $detalle->update(['cantidad_recibida' => $cantidad, 'nota_recepcion' => $nota]);
            }

            $r->update(['estado' => EstadoRequisicion::RECIBIDA, 'recibido_por' => $usuario->id, 'recibido_en' => now()]);

            return $r->load('detalles');
        });
    }

    /** Cancela antes de despachar (lo puede hacer quien pidió, o quien surte desde el origen). */
    public function cancelar(Requisicion $requisicion, Empresa $empresa, User $usuario, string $motivo): Requisicion
    {
        $r = $requisicion->loadMissing('localidadOrigen', 'localidadDestino');

        if (! $this->esDeSuSucursal($usuario, $r->localidadDestino) && ! $this->esDeSuSucursal($usuario, $r->localidadOrigen)) {
            throw new RuntimeException('Solo las sucursales involucradas pueden cancelar esta requisición.');
        }

        return $this->cerrarSinMovimiento($requisicion, $empresa, $usuario, $motivo, EstadoRequisicion::CANCELADA, [EstadoRequisicion::PENDIENTE_APROBACION, EstadoRequisicion::PENDIENTE]);
    }

    /**
     * Movimiento interno directo (un solo paso) entre localidades de la misma sucursal: p. ej. de
     * Recibo a Almacén. Queda como documento MI-xxxxx con sus dos movimientos en el Kardex.
     *
     * @param  array<int, array{producto_id: int, cantidad: float|int|string}>  $lineas
     */
    public function moverInterno(Empresa $empresa, User $usuario, int $origenId, int $destinoId, array $lineas, ?string $notas = null): Requisicion
    {
        if (! $usuario->can('inventario.mover')) {
            throw new RuntimeException('No tienes permiso para mover mercancía entre localidades.');
        }

        [$origen, $destino] = $this->validarLocalidades($empresa, $origenId, $destinoId);

        if ($origen->sucursal_id !== $destino->sucursal_id) {
            throw new RuntimeException('Un movimiento interno es dentro de la misma sucursal. Entre sucursales se usa una requisición.');
        }

        $this->exigirSucursal($usuario, $origen, 'mover mercancía en esta sucursal');
        $detalles = $this->validarLineas($empresa, $lineas);

        return DB::transaction(function () use ($empresa, $usuario, $origen, $destino, $detalles, $notas) {
            $ahora = now();
            $r = Requisicion::create([
                'empresa_id' => $empresa->id,
                'numero' => $this->siguienteNumero($empresa),
                'tipo' => Requisicion::TIPO_MOVIMIENTO,
                'estado' => EstadoRequisicion::RECIBIDA,
                'localidad_origen_id' => $origen->id,
                'localidad_destino_id' => $destino->id,
                'sucursal_origen_id' => $origen->sucursal_id,
                'sucursal_destino_id' => $destino->sucursal_id,
                'entre_sucursales' => false,
                'notas' => filled($notas) ? mb_substr(trim($notas), 0, 500) : null,
                'solicitado_por' => $usuario->id,
                'despachado_por' => $usuario->id,
                'despachado_en' => $ahora,
                'recibido_por' => $usuario->id,
                'recibido_en' => $ahora,
            ]);

            foreach ($detalles as $d) {
                $producto = $d['producto'];
                $this->inventario->registrarMovimiento($producto, TipoMovimiento::SALIDA, OrigenMovimiento::MOVIMIENTO_INTERNO, $d['cantidad'], $r->id, $usuario->id, "{$r->etiquetaNumero()} → {$destino->nombre}", localidad: $origen);
                $this->inventario->registrarMovimiento($producto, TipoMovimiento::ENTRADA, OrigenMovimiento::MOVIMIENTO_INTERNO, $d['cantidad'], $r->id, $usuario->id, "{$r->etiquetaNumero()} ← {$origen->nombre}", localidad: $destino);

                $r->detalles()->create([
                    'producto_id' => $producto->id,
                    'cantidad_solicitada' => $d['cantidad'],
                    'cantidad_despachada' => $d['cantidad'],
                    'cantidad_recibida' => $d['cantidad'],
                ]);
            }

            return $r->load('detalles');
        });
    }

    public function esDeSuSucursal(User $usuario, Localidad $localidad): bool
    {
        if ($usuario->can('sucursales.administrar')) {
            return true;
        }

        $sucursalUsuario = $usuario->sucursal_id ?? $this->localidades->principal($localidad->empresa)->id;

        return (int) $sucursalUsuario === (int) $localidad->sucursal_id;
    }

    // ──────────────────────────────────────────────────────────────────────

    /** @return array{0: Localidad, 1: Localidad} */
    private function validarLocalidades(Empresa $empresa, int $origenId, int $destinoId): array
    {
        if ($origenId === $destinoId) {
            throw new RuntimeException('El origen y el destino deben ser localidades distintas.');
        }

        $origen = $this->localidades->localidadDeEmpresa($empresa, $origenId);
        $destino = $this->localidades->localidadDeEmpresa($empresa, $destinoId);

        if ($destino->tipo === \App\Enums\TipoLocalidad::RECEPCION) {
            throw new RuntimeException("{$destino->nombre} es la localidad de recepción: ahí solo entra mercancía al registrar una compra.");
        }

        return [$origen, $destino];
    }

    /** @return array<int, array{producto_id: int, producto: Producto, cantidad: string}> */
    private function validarLineas(Empresa $empresa, array $lineas): array
    {
        $lineas = array_values(array_filter($lineas, fn ($l) => filled($l['producto_id'] ?? null)));

        if ($lineas === []) {
            throw new RuntimeException('Agrega al menos un producto.');
        }

        if (count($lineas) > 300) {
            throw new RuntimeException('Máximo 300 productos por documento.');
        }

        $productos = Producto::where('empresa_id', $empresa->id)
            ->where('controla_stock', true)
            ->whereIn('id', array_map(fn ($l) => (int) $l['producto_id'], $lineas))
            ->get()->keyBy('id');

        $resultado = [];
        $vistos = [];
        foreach ($lineas as $l) {
            $producto = $productos->get((int) $l['producto_id']);

            if ($producto === null) {
                throw new RuntimeException('Un producto no existe, es de otra empresa o no controla inventario.');
            }

            if (isset($vistos[$producto->id])) {
                throw new RuntimeException("«{$producto->nombre}» está repetido: ponlo una sola vez con la cantidad total.");
            }
            $vistos[$producto->id] = true;

            $resultado[] = ['producto_id' => $producto->id, 'producto' => $producto, 'cantidad' => $this->cantidadValida($l['cantidad'] ?? null, '99999999', positiva: true)];
        }

        return $resultado;
    }

    private function cantidadValida(mixed $valor, string $maximo, bool $positiva = false): string
    {
        $valor = trim((string) $valor);

        if ($valor === '' || ! is_numeric($valor) || bccomp($valor, '0', 3) < 0) {
            throw new RuntimeException('Cantidad inválida.');
        }

        if ($positiva && bccomp($valor, '0', 3) === 0) {
            throw new RuntimeException('La cantidad debe ser mayor que cero.');
        }

        if (bccomp($valor, $maximo, 3) > 0) {
            throw new RuntimeException("La cantidad no puede pasar de {$maximo}.");
        }

        return bcadd($valor, '0', 3);
    }

    private function bloquear(Requisicion $requisicion, Empresa $empresa): Requisicion
    {
        $r = Requisicion::where('empresa_id', $empresa->id)->lockForUpdate()->find($requisicion->id);

        if ($r === null) {
            throw new RuntimeException('La requisición no existe.');
        }

        return $r->load(['detalles.producto', 'localidadOrigen.sucursal', 'localidadDestino.sucursal']);
    }

    private function exigirEstado(Requisicion $r, EstadoRequisicion $estado): void
    {
        if ($r->estado !== $estado) {
            throw new RuntimeException("La requisición {$r->etiquetaNumero()} está «{$r->estado->etiqueta()}»: no se puede hacer esto ahora.");
        }
    }

    private function exigirPermiso(User $usuario, string $permiso, string $accion): void
    {
        if (! $usuario->can($permiso)) {
            throw new RuntimeException("No tienes permiso para {$accion}.");
        }
    }

    private function exigirSucursal(User $usuario, Localidad $localidad, string $accion): void
    {
        if (! $this->esDeSuSucursal($usuario, $localidad)) {
            throw new RuntimeException("No puedes {$accion}: pertenece a otra sucursal.");
        }
    }

    /** @param  array<EstadoRequisicion>  $desde */
    private function cerrarSinMovimiento(Requisicion $requisicion, Empresa $empresa, User $usuario, string $motivo, EstadoRequisicion $nuevo, array $desde): Requisicion
    {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new RuntimeException('Indica el motivo.');
        }

        return DB::transaction(function () use ($requisicion, $empresa, $usuario, $motivo, $nuevo, $desde) {
            $r = $this->bloquear($requisicion, $empresa);

            if (! in_array($r->estado, $desde, true)) {
                throw new RuntimeException("La requisición {$r->etiquetaNumero()} está «{$r->estado->etiqueta()}»: ya no se puede cerrar así.");
            }

            $r->update(['estado' => $nuevo, 'cancelado_por' => $usuario->id, 'cancelado_en' => now(), 'motivo_cancelacion' => mb_substr($motivo, 0, 255)]);

            return $r;
        });
    }

    private function siguienteNumero(Empresa $empresa): int
    {
        Empresa::whereKey($empresa->id)->lockForUpdate()->first();

        return (int) Requisicion::where('empresa_id', $empresa->id)->max('numero') + 1;
    }
}
