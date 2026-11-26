<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EstadoPedidoVenta;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\PedidoVenta;
use App\Models\Producto;
use App\Models\ProductoPresentacion;
use App\Models\User;
use App\Models\Venta;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pedidos a caja: el vendedor de piso arma el pedido a nombre del cliente y la caja lo cobra.
 *
 * - El pedido no reserva stock ni fija precio: es una lista de QUÉ y CUÁNTO. El precio que se cobra
 *   es el que el POS lee de la base al cobrar (PuntoDeVenta::sanearCarrito()).
 * - Cobrar bloquea la fila del pedido (lockForUpdate) en la misma transacción que registra la venta:
 *   dos cajeros que carguen el mismo pedido no lo pueden cobrar dos veces.
 */
class PedidoVentaService
{
    /**
     * @param  array<int, array{producto_id: int, presentacion_id?: int|null, cantidad: float|int|string, precio?: string|null}>  $lineas
     *   'precio' solo lo usa enviarACaja() de una cotización (precio ya negociado); desde la
     *   pantalla de pedidos nunca se pasa y vale el precio de la base.
     */
    public function crear(Empresa $empresa, User $vendedor, string $nombreCliente, array $lineas, ?int $clienteId = null, ?string $notas = null, ?int $cotizacionId = null): PedidoVenta
    {
        $nombreCliente = trim(preg_replace('/\s+/', ' ', $nombreCliente) ?? '');

        if ($nombreCliente === '') {
            throw new RuntimeException('Escribe el nombre del cliente para el pedido.');
        }

        if (mb_strlen($nombreCliente) > 120) {
            throw new RuntimeException('El nombre del cliente no puede pasar de 120 caracteres.');
        }

        if ($lineas === []) {
            throw new RuntimeException('El pedido no tiene productos.');
        }

        if (count($lineas) > 200) {
            throw new RuntimeException('Un pedido no puede tener más de 200 líneas.');
        }

        if ($clienteId !== null && ! Cliente::where('empresa_id', $empresa->id)->whereKey($clienteId)->exists()) {
            throw new RuntimeException('El cliente seleccionado no pertenece a esta empresa.');
        }

        $productos = Producto::query()
            ->where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->whereIn('id', array_map(fn ($l) => (int) ($l['producto_id'] ?? 0), $lineas))
            ->get()
            ->keyBy('id');

        $idsPresentacion = array_filter(array_map(fn ($l) => (int) ($l['presentacion_id'] ?? 0), $lineas));
        $presentaciones = $idsPresentacion === []
            ? collect()
            : ProductoPresentacion::query()
                ->where('empresa_id', $empresa->id)
                ->whereIn('id', $idsPresentacion)
                ->get()
                ->keyBy('id');

        $detalles = [];
        $total = '0.00';

        foreach ($lineas as $linea) {
            $producto = $productos->get((int) ($linea['producto_id'] ?? 0));

            if ($producto === null) {
                throw new RuntimeException('Un producto del pedido no existe o está inactivo.');
            }

            $cantidad = (string) ($linea['cantidad'] ?? '0');

            if (! is_numeric($cantidad) || bccomp($cantidad, '0', 3) <= 0 || bccomp($cantidad, '99999', 3) > 0) {
                throw new RuntimeException("Cantidad inválida para {$producto->nombre}.");
            }

            $presentacion = null;

            if (! empty($linea['presentacion_id'])) {
                $presentacion = $presentaciones->get((int) $linea['presentacion_id']);

                if ($presentacion === null || $presentacion->producto_id !== $producto->id) {
                    throw new RuntimeException("La presentación elegida para {$producto->nombre} no es válida.");
                }
            }

            $precio = isset($linea['precio']) && $cotizacionId !== null
                ? (string) $linea['precio']
                : (string) ($presentacion?->precio ?? $producto->precio ?? 0);

            $detalles[] = [
                'producto_id' => $producto->id,
                'presentacion_id' => $presentacion?->id,
                'descripcion' => mb_substr($presentacion !== null && ! $presentacion->es_base
                    ? "{$producto->nombre} - {$presentacion->nombre}"
                    : $producto->nombre, 0, 255),
                'cantidad' => bcadd($cantidad, '0', 3),
                'precio_estimado' => bcadd($precio, '0', 2),
            ];

            $total = bcadd($total, bcmul($precio, $cantidad, 4), 2);
        }

        return DB::transaction(function () use ($empresa, $vendedor, $nombreCliente, $clienteId, $notas, $detalles, $total, $cotizacionId) {
            // Serializa la numeración por empresa: dos vendedores enviando a la vez no sacan el mismo
            // número (el índice único lo garantiza igual, esto evita que uno falle por la carrera).
            Empresa::whereKey($empresa->id)->lockForUpdate()->first();

            $numero = (int) PedidoVenta::where('empresa_id', $empresa->id)->max('numero') + 1;

            $pedido = PedidoVenta::create([
                'empresa_id' => $empresa->id,
                'numero' => $numero,
                'nombre_cliente' => $nombreCliente,
                'cliente_id' => $clienteId,
                'cotizacion_id' => $cotizacionId,
                'vendedor_id' => $vendedor->id,
                'estado' => EstadoPedidoVenta::PENDIENTE,
                'notas' => filled($notas) ? mb_substr(trim($notas), 0, 500) : null,
                'total_estimado' => $total,
            ]);

            $pedido->detalles()->createMany($detalles);

            return $pedido->load('detalles');
        });
    }

    /**
     * Pedidos pendientes de la empresa, más recientes primero. Busca por nombre (sin distinguir
     * mayúsculas) o por número ("15", "P-0015").
     *
     * @return Collection<int, PedidoVenta>
     */
    public function pendientes(Empresa $empresa, string $busqueda = '', int $limite = 30): Collection
    {
        $busqueda = trim($busqueda);

        return PedidoVenta::query()
            ->where('empresa_id', $empresa->id)
            ->pendientes()
            ->when($busqueda !== '', function ($q) use ($busqueda) {
                $numero = (int) preg_replace('/\D/', '', $busqueda);
                $q->where(function ($w) use ($busqueda, $numero) {
                    $w->where('nombre_cliente', 'ilike', '%'.addcslashes($busqueda, '%_\\').'%');

                    if ($numero > 0) {
                        $w->orWhere('numero', $numero);
                    }
                });
            })
            ->with('vendedor:id,name')
            ->withCount('detalles')
            ->latest()
            ->limit($limite)
            ->get();
    }

    /** Pedido pendiente de la empresa, con sus líneas. Null si no existe, es de otra empresa o ya no está pendiente. */
    public function pendienteParaCargar(Empresa $empresa, int $pedidoId): ?PedidoVenta
    {
        return PedidoVenta::query()
            ->where('empresa_id', $empresa->id)
            ->pendientes()
            ->with(['detalles.producto', 'detalles.presentacion'])
            ->find($pedidoId);
    }

    /**
     * Cobra el pedido: bloquea su fila, comprueba que siga pendiente, ejecuta $registrarVenta (que
     * debe llamar a VentaService::registrar) y lo marca como cobrado, todo en una transacción.
     *
     * @param  Closure(): Venta  $registrarVenta
     */
    public function cobrar(Empresa $empresa, int $pedidoId, int $cajeroId, Closure $registrarVenta): Venta
    {
        return DB::transaction(function () use ($empresa, $pedidoId, $cajeroId, $registrarVenta) {
            $pedido = PedidoVenta::query()
                ->where('empresa_id', $empresa->id)
                ->lockForUpdate()
                ->find($pedidoId);

            if ($pedido === null) {
                throw new RuntimeException('El pedido no existe.');
            }

            if (! $pedido->estaPendiente()) {
                throw new RuntimeException("El pedido {$pedido->etiquetaNumero()} ya fue {$pedido->estado->etiqueta()}: no se puede cobrar de nuevo.");
            }

            $venta = $registrarVenta();

            $pedido->update([
                'estado' => EstadoPedidoVenta::COBRADO,
                'venta_id' => $venta->id,
                'cobrado_por' => $cajeroId,
                'cobrado_en' => now(),
            ]);

            // Pedido que vino de una cotización: queda facturada y enlazada a la venta.
            if ($pedido->cotizacion_id !== null) {
                \App\Models\Cotizacion::where('empresa_id', $empresa->id)
                    ->whereKey($pedido->cotizacion_id)
                    ->update(['estado' => \App\Enums\EstadoCotizacion::FACTURADA->value, 'venta_id' => $venta->id]);
            }

            return $venta;
        });
    }

    public function cancelar(PedidoVenta $pedido, Empresa $empresa, int $userId, string $motivo): PedidoVenta
    {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new RuntimeException('Indica el motivo de la cancelación.');
        }

        return DB::transaction(function () use ($pedido, $empresa, $userId, $motivo) {
            $bloqueado = PedidoVenta::query()
                ->where('empresa_id', $empresa->id)
                ->lockForUpdate()
                ->findOrFail($pedido->id);

            if (! $bloqueado->estaPendiente()) {
                throw new RuntimeException('Solo se pueden cancelar pedidos pendientes.');
            }

            $bloqueado->update([
                'estado' => EstadoPedidoVenta::CANCELADO,
                'cancelado_por' => $userId,
                'cancelado_en' => now(),
                'motivo_cancelacion' => mb_substr($motivo, 0, 255),
            ]);

            return $bloqueado;
        });
    }
}
