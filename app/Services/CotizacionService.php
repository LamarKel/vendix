<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EstadoCotizacion;
use App\Enums\OrigenVenta;
use App\Models\Cotizacion;
use App\Models\DetalleCotizacion;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CotizacionService
{
    public function __construct(
        private readonly VentaService $ventaService,
    ) {}

    public function generarNumero(Empresa $empresa): string
    {
        // Se llama dentro de la transacción que crea el documento: bloquear la fila de la empresa
        // serializa la numeración, así dos usuarios guardando a la vez no chocan con el índice
        // único (empresa_id, numero) ni ven un error.
        Empresa::whereKey($empresa->id)->lockForUpdate()->first();

        // withTrashed: el índice único incluye los borrados; sin esto se repetiría su número.
        $ultimo = Cotizacion::withTrashed()->where('empresa_id', $empresa->id)
            ->orderByDesc('id')
            ->value('numero');

        $siguiente = $ultimo ? ((int) Str::after($ultimo, 'COT-')) + 1 : 1;

        return 'COT-'.str_pad((string) $siguiente, 5, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array{
     *   cliente_id: int|null,
     *   fecha: string,
     *   dias_vigencia: int,
     *   condiciones_pago: string|null,
     *   notas: string|null,
     *   lineas: array<int, array{producto_id: int, cantidad: float|string, precio_unitario: float|string, descuento?: float|string}>
     * } $datos
     */
    public function crear(array $datos, int $userId, Empresa $empresa): Cotizacion
    {
        $datos['lineas'] = array_values(array_filter(
            $datos['lineas'] ?? [],
            fn (array $l) => filled($l['producto_id'] ?? null)
                && filled($l['cantidad'] ?? null)
                && filled($l['precio_unitario'] ?? null),
        ));

        if (empty($datos['lineas'])) {
            throw new RuntimeException('La cotización debe tener al menos una línea.');
        }

        return DB::transaction(function () use ($datos, $userId, $empresa) {
            $diasVigencia = (int) ($datos['dias_vigencia'] ?? 15);
            $fecha = $datos['fecha'];

            $cotizacion = Cotizacion::create([
                'empresa_id' => $empresa->id,
                'cliente_id' => $datos['cliente_id'] ?? null,
                'user_id' => $userId,
                'numero' => $this->generarNumero($empresa),
                'fecha' => $fecha,
                'fecha_vencimiento' => now()->parse($fecha)->addDays($diasVigencia)->toDateString(),
                'dias_vigencia' => $diasVigencia,
                'condiciones_pago' => $datos['condiciones_pago'] ?? null,
                'notas' => $datos['notas'] ?? null,
                'estado' => EstadoCotizacion::BORRADOR,
            ]);

            $subtotalTotal = '0';
            $descuentoTotal = '0';
            $itbisTotal = '0';

            foreach ($datos['lineas'] as $linea) {
                $producto = Producto::where('empresa_id', $empresa->id)
                    ->findOrFail($linea['producto_id']);

                $cantidad = (string) $linea['cantidad'];
                $precioUnitario = (string) $linea['precio_unitario'];
                $descuentoLinea = (string) ($linea['descuento'] ?? '0');

                $bruto = bcmul($cantidad, $precioUnitario, 2);
                $subtotal = bcsub($bruto, $descuentoLinea, 2);

                $porcentaje = (string) $producto->tasa_itbis->porcentaje();
                $itbis = bcdiv(bcmul($subtotal, $porcentaje, 4), '100', 2);

                DetalleCotizacion::create([
                    'cotizacion_id' => $cotizacion->id,
                    'producto_id' => $producto->id,
                    'cantidad' => $cantidad,
                    'precio_unitario' => $precioUnitario,
                    'descuento' => $descuentoLinea,
                    'itbis' => $itbis,
                    'subtotal' => $subtotal,
                ]);

                $subtotalTotal = bcadd($subtotalTotal, $subtotal, 2);
                $descuentoTotal = bcadd($descuentoTotal, $descuentoLinea, 2);
                $itbisTotal = bcadd($itbisTotal, $itbis, 2);
            }

            $cotizacion->update([
                'subtotal' => $subtotalTotal,
                'descuento' => $descuentoTotal,
                'itbis' => $itbisTotal,
                'total' => bcadd($subtotalTotal, $itbisTotal, 2),
            ]);

            return $cotizacion->refresh();
        });
    }

    public function convertirAVenta(Cotizacion $cotizacion, Empresa $empresa, array $opcionesVenta = []): Venta
    {
        if ((int) $cotizacion->empresa_id !== (int) $empresa->id) {
            throw new RuntimeException('La cotización no pertenece a esta empresa.');
        }

        if (! $cotizacion->puedeConvertirse()) {
            throw new RuntimeException('La cotización no se puede convertir: debe estar aprobada y sin facturar.');
        }

        $cotizacion->load('detalles');

        return DB::transaction(function () use ($cotizacion, $empresa, $opcionesVenta) {
            $lineas = $cotizacion->detalles->map(fn (DetalleCotizacion $d) => [
                'producto_id' => $d->producto_id,
                'cantidad' => (float) $d->cantidad,
                'precio_unitario' => (float) $d->precio_unitario,
            ])->toArray();

            $venta = $this->ventaService->registrar([
                'origen' => OrigenVenta::COTIZACION,
                'cliente_id' => $cotizacion->cliente_id,
                'user_id' => auth()->id(),
                'lineas' => $lineas,
                'tipo_comprobante' => $opcionesVenta['tipo_comprobante'] ?? null,
                'forma_pago' => $opcionesVenta['forma_pago'] ?? null,
                'tipo_pago' => $opcionesVenta['tipo_pago'] ?? null,
                'sin_comprobante' => $opcionesVenta['sin_comprobante'] ?? false,
            ], $empresa);

            $cotizacion->update([
                'venta_id' => $venta->id,
                'estado' => EstadoCotizacion::FACTURADA,
            ]);

            return $venta;
        });
    }

    /**
     * Manda la cotización aprobada a caja como pedido en espera, a nombre del cliente. La caja la
     * cobra al precio cotizado (descuento de línea ya incluido en el precio unitario) mientras esté
     * vigente; vencida, se cobra al precio actual (ver PuntoDeVenta::sanearCarrito()).
     */
    public function enviarACaja(Cotizacion $cotizacion, Empresa $empresa, \App\Models\User $usuario): \App\Models\PedidoVenta
    {
        if ((int) $cotizacion->empresa_id !== (int) $empresa->id) {
            throw new RuntimeException('La cotización no pertenece a esta empresa.');
        }

        if (! $cotizacion->puedeConvertirse()) {
            throw new RuntimeException('Solo se puede enviar a caja una cotización aprobada y sin facturar.');
        }

        if ($cotizacion->fecha_vencimiento?->endOfDay()->isPast()) {
            throw new RuntimeException('La cotización está vencida: duplícala para actualizar los precios.');
        }

        $yaEnviada = \App\Models\PedidoVenta::where('empresa_id', $empresa->id)
            ->where('cotizacion_id', $cotizacion->id)
            ->where('estado', \App\Enums\EstadoPedidoVenta::PENDIENTE->value)
            ->first();

        if ($yaEnviada !== null) {
            throw new RuntimeException("Ya está en caja como pedido {$yaEnviada->etiquetaNumero()}.");
        }

        $cotizacion->load('detalles', 'cliente');

        $lineas = $cotizacion->detalles->map(function (DetalleCotizacion $d) {
            $cantidad = (string) $d->cantidad;
            $precio = (string) $d->precio_unitario;

            // El descuento de la línea se reparte en el precio unitario: la caja no tiene
            // descuento libre por línea.
            if (bccomp((string) $d->descuento, '0', 2) > 0 && bccomp($cantidad, '0', 4) > 0) {
                $precio = bcsub($precio, bcdiv((string) $d->descuento, $cantidad, 4), 2);
            }

            return ['producto_id' => $d->producto_id, 'cantidad' => $cantidad, 'precio' => $precio];
        })->all();

        return app(PedidoVentaService::class)->crear(
            $empresa,
            $usuario,
            $cotizacion->cliente?->nombre ?? "Cotización {$cotizacion->numero}",
            $lineas,
            $cotizacion->cliente_id,
            "Cotización {$cotizacion->numero}",
            $cotizacion->id,
        );
    }

    public function duplicar(Cotizacion $cotizacion, Empresa $empresa): Cotizacion
    {
        if ((int) $cotizacion->empresa_id !== (int) $empresa->id) {
            throw new RuntimeException('La cotización no pertenece a esta empresa.');
        }

        $cotizacion->load('detalles');

        $lineas = $cotizacion->detalles->map(fn (DetalleCotizacion $d) => [
            'producto_id' => $d->producto_id,
            'cantidad' => (string) $d->cantidad,
            'precio_unitario' => (string) $d->precio_unitario,
            'descuento' => (string) $d->descuento,
        ])->toArray();

        return $this->crear([
            'cliente_id' => $cotizacion->cliente_id,
            'fecha' => now()->toDateString(),
            'dias_vigencia' => $cotizacion->dias_vigencia,
            'condiciones_pago' => $cotizacion->condiciones_pago,
            'notas' => $cotizacion->notas,
            'lineas' => $lineas,
        ], auth()->id(), $empresa);
    }

    public function vencerExpiradas(): int
    {
        return Cotizacion::whereIn('estado', [
            EstadoCotizacion::BORRADOR->value,
            EstadoCotizacion::ENVIADA->value,
        ])
            ->where('fecha_vencimiento', '<', now()->startOfDay())
            ->update(['estado' => EstadoCotizacion::VENCIDA->value]);
    }
}
