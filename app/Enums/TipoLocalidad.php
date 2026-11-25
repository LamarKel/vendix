<?php

declare(strict_types=1);

namespace App\Enums;

enum TipoLocalidad: string
{
    case VENTA = 'venta';
    case RECEPCION = 'recepcion';
    case ALMACENAJE = 'almacenaje';

    public function etiqueta(): string
    {
        return match ($this) {
            self::VENTA => 'Venta (de aquí descuentan las cajas)',
            self::RECEPCION => 'Recepción (aquí entran las compras)',
            self::ALMACENAJE => 'Almacenaje',
        };
    }

    public function corta(): string
    {
        return match ($this) {
            self::VENTA => 'Venta',
            self::RECEPCION => 'Recepción',
            self::ALMACENAJE => 'Almacenaje',
        };
    }

    /** Venta y recepción son únicas por sucursal. */
    public function esUnicaPorSucursal(): bool
    {
        return $this !== self::ALMACENAJE;
    }
}
