<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Pantalla o flujo desde el que se cobró una venta. Lo fija quien llama a VentaService (nunca el
 * navegador) y no cambia después. Las notas de crédito/débito heredan el de la venta que modifican.
 *
 * Sirve para reportes por canal y
 * para saber dónde se originó un documento sin adivinarlo por códigos de producto.
 */
enum OrigenVenta: string
{
    case POS = 'pos';
    case POS_TACTIL = 'pos_tactil';
    case COTIZACION = 'cotizacion';

    public function etiqueta(): string
    {
        return match ($this) {
            self::POS => 'Punto de venta',
            self::POS_TACTIL => 'POS táctil',
            self::COTIZACION => 'Cotización',
        };
    }
}
