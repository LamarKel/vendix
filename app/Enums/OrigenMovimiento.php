<?php

namespace App\Enums;

enum OrigenMovimiento: string
{
    case VENTA             = 'venta';
    case COMPRA            = 'compra';
    case AJUSTE            = 'ajuste';
    case ANULACION         = 'anulacion';
    case DEVOLUCION_COMPRA = 'devolucion_compra';
    case DEVOLUCION_VENTA      = 'devolucion_venta';
    case RECEPCION_ORDEN_COMPRA = 'recepcion_orden_compra';
    /** Producto devuelto por un cliente que llegó dañado o vencido: sale del inventario como pérdida. */
    case MERMA = 'merma';
    /** Despacho de una requisición: sale de la localidad que surte. */
    case REQUISICION_DESPACHO = 'requisicion_despacho';
    /** Recepción de una requisición: entra a la localidad que pidió. */
    case REQUISICION_RECEPCION = 'requisicion_recepcion';
    /** Movimiento interno directo entre localidades de la misma sucursal. */
    case MOVIMIENTO_INTERNO = 'movimiento_interno';
    /** Ajuste por diferencia de un conteo físico aprobado. */
    case CONTEO = 'conteo';

    public function etiqueta(): string
    {
        return match ($this) {
            self::VENTA => 'Venta',
            self::COMPRA => 'Compra',
            self::AJUSTE => 'Ajuste',
            self::ANULACION => 'Anulación',
            self::DEVOLUCION_COMPRA => 'Devolución a proveedor',
            self::DEVOLUCION_VENTA => 'Devolución de cliente',
            self::RECEPCION_ORDEN_COMPRA => 'Recepción de orden de compra',
            self::MERMA => 'Merma',
            self::REQUISICION_DESPACHO => 'Requisición (despacho)',
            self::REQUISICION_RECEPCION => 'Requisición (recepción)',
            self::MOVIMIENTO_INTERNO => 'Movimiento interno',
            self::CONTEO => 'Conteo físico',
        };
    }
}
