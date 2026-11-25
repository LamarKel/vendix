<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MotivoMerma;
use App\Enums\OrigenMovimiento;
use App\Enums\TipoLocalidad;
use App\Enums\TipoMovimiento;
use App\Exceptions\StockInsuficienteException;
use App\Models\Empresa;
use App\Models\Localidad;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\StockLocalidad;
use RuntimeException;

class InventarioService
{
    /**
     * Mientras InventarioService escribe productos.stock, el observer de Producto no debe
     * "sincronizar" la diferencia a la localidad de venta (ya la movió este servicio).
     */
    public static bool $moviendo = false;

    /** Únicos orígenes que pueden SUMAR existencia en una localidad de recepción. */
    private const ENTRAN_A_RECEPCION = [
        OrigenMovimiento::COMPRA,
        OrigenMovimiento::RECEPCION_ORDEN_COMPRA,
        OrigenMovimiento::ANULACION, // reverso de una devolución a proveedor que salió de ahí
    ];

    public function __construct(private readonly LocalidadService $localidades) {}

    /**
     * Único punto que mueve stock y registra el Kardex.
     *
     * Debe ejecutarse dentro de una transacción abierta por el llamador (la operación
     * de negocio completa —venta, compra, ajuste— debe ser atómica).
     *
     * El stock real vive por LOCALIDAD (stock_localidades); productos.stock es el total de la
     * empresa y se mueve en la misma cantidad. Sin $localidad, se usa la de la sucursal principal
     * que corresponde al origen: compras y devoluciones a proveedor → recepción (o venta, si la
     * empresa no usa recepción); lo demás → venta.
     *
     * Stock negativo: una salida que deja la LOCALIDAD por debajo de cero se rechaza, salvo que el
     * llamador pase $permitirNegativo (solo VentaService, si la empresa activó "vender sin stock")
     * y la localidad sea la de venta. Transferencias, ajustes, anular compras y devolver al
     * proveedor nunca pueden dejar una localidad en negativo. Las entradas nunca se rechazan.
     *
     * Cada movimiento guarda el costo del producto en ese momento (costo promedio antes de
     * moverlo), salvo que el llamador pase $costoUnitario.
     *
     * @throws StockInsuficienteException si el movimiento dejaría la localidad en negativo y no se permite.
     */
    public function registrarMovimiento(
        Producto $producto,
        TipoMovimiento $tipo,
        OrigenMovimiento $origen,
        string $cantidad,
        ?int $referenciaId = null,
        ?int $userId = null,
        ?string $observacion = null,
        bool $permitirNegativo = false,
        ?MotivoMerma $motivo = null,
        ?string $costoUnitario = null,
        ?int $revierteMovimientoId = null,
        ?Localidad $localidad = null,
    ): ?MovimientoInventario {
        if (! $producto->controla_stock) {
            return null;
        }

        // Re-lee con lock para evitar condiciones de carrera con movimientos concurrentes.
        $producto = Producto::lockForUpdate()->findOrFail($producto->id);

        $localidad ??= $this->localidadPorDefecto($producto->empresa, $origen);

        if ((int) $localidad->empresa_id !== (int) $producto->empresa_id) {
            throw new RuntimeException('La localidad no pertenece a la empresa del producto.');
        }

        $delta = match ($tipo) {
            TipoMovimiento::ENTRADA => $cantidad,
            TipoMovimiento::SALIDA => bcsub('0', $cantidad, 3),
            TipoMovimiento::AJUSTE => $cantidad, // ya trae el signo
        };

        // A Recepción solo entra mercancía de compras (o el reverso de una devolución a proveedor).
        // Lo que ya salió de Recepción no vuelve ahí por requisición, movimiento ni ajuste.
        if ($localidad->tipo === TipoLocalidad::RECEPCION && bccomp($delta, '0', 3) > 0 && ! in_array($origen, self::ENTRAN_A_RECEPCION, true)) {
            throw new RuntimeException("A {$localidad->nombre} solo entra mercancía al registrar una compra.");
        }

        $fila = $this->filaBloqueada($localidad, $producto);

        $locAnterior = (string) $fila->cantidad;
        $locNuevo = bcadd($locAnterior, $delta, 3);
        $stockAnterior = (string) $producto->stock;
        $stockNuevo = bcadd($stockAnterior, $delta, 3);

        $bajaDeCero = bccomp($locNuevo, '0', 3) < 0 && bccomp($delta, '0', 3) < 0;
        $negativoPermitido = $permitirNegativo && $localidad->tipo === TipoLocalidad::VENTA;

        if ($bajaDeCero && ! $negativoPermitido) {
            $solicitado = bccomp($cantidad, '0', 3) < 0 ? bcsub('0', $cantidad, 3) : $cantidad;
            throw new StockInsuficienteException(
                "Stock insuficiente para «{$producto->nombre}» en {$localidad->nombre}: disponible {$locAnterior}, solicitado {$solicitado}."
            );
        }

        $fila->update(['cantidad' => $locNuevo]);

        self::$moviendo = true;
        try {
            $producto->update(['stock' => $stockNuevo]);
        } finally {
            self::$moviendo = false;
        }

        return MovimientoInventario::create([
            'empresa_id' => $producto->empresa_id,
            'producto_id' => $producto->id,
            'localidad_id' => $localidad->id,
            'tipo' => $tipo,
            'origen' => $origen,
            'motivo' => $motivo,
            'referencia_id' => $referenciaId,
            'revierte_movimiento_id' => $revierteMovimientoId,
            'cantidad' => $cantidad,
            'costo_unitario' => $costoUnitario ?? (string) $producto->costo,
            'stock_anterior' => $stockAnterior,
            'stock_nuevo' => $stockNuevo,
            'stock_localidad_anterior' => $locAnterior,
            'stock_localidad_nuevo' => $locNuevo,
            'user_id' => $userId,
            'observacion' => $observacion,
            'dejo_stock_negativo' => $bajaDeCero,
        ]);
    }

    /** Existencia de un producto en una localidad (0 si no tiene fila). */
    public function stockEn(Localidad $localidad, Producto $producto): string
    {
        return (string) (StockLocalidad::where('localidad_id', $localidad->id)
            ->where('producto_id', $producto->id)
            ->value('cantidad') ?? '0');
    }

    /**
     * Mantiene sum(stock_localidades) = productos.stock cuando algo cambia productos.stock por
     * fuera de este servicio (alta de producto con stock inicial, importación, datos viejos): la
     * diferencia va a la localidad de venta de la sucursal principal. No registra Kardex (lo que
     * cambió el total tampoco lo hizo).
     */
    public function sincronizarDiferencia(Producto $producto, string $diferencia): void
    {
        // Recién creado sin el atributo en memoria: el default de la columna está en la base.
        $controla = $producto->controla_stock ?? Producto::whereKey($producto->id)->value('controla_stock');

        if (bccomp($diferencia, '0', 3) === 0 || ! $controla || $producto->empresa === null) {
            return;
        }

        $localidad = $this->localidades->localidadDeVenta($this->localidades->principal($producto->empresa));
        $fila = $this->filaBloqueada($localidad, $producto);
        $fila->update(['cantidad' => bcadd((string) $fila->cantidad, $diferencia, 3)]);
    }

    private function filaBloqueada(Localidad $localidad, Producto $producto): StockLocalidad
    {
        StockLocalidad::query()->insertOrIgnore([
            'empresa_id' => $producto->empresa_id,
            'localidad_id' => $localidad->id,
            'producto_id' => $producto->id,
            'cantidad' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return StockLocalidad::where('localidad_id', $localidad->id)
            ->where('producto_id', $producto->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function localidadPorDefecto(Empresa $empresa, OrigenMovimiento $origen): Localidad
    {
        $sucursal = $this->localidades->principal($empresa);

        return match ($origen) {
            OrigenMovimiento::COMPRA, OrigenMovimiento::RECEPCION_ORDEN_COMPRA, OrigenMovimiento::DEVOLUCION_COMPRA => $this->localidadDeCompras($empresa, $sucursal),
            default => $this->localidades->localidadDeVenta($sucursal),
        };
    }

    /** A dónde entran las compras de una sucursal según la configuración de la empresa. */
    public function localidadDeCompras(Empresa $empresa, \App\Models\Sucursal $sucursal): Localidad
    {
        return $empresa->config()->compras_a_recepcion
            ? $this->localidades->localidadDeRecepcion($sucursal)
            : $this->localidades->localidadDeVenta($sucursal);
    }
}
