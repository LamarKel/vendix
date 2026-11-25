<?php

declare(strict_types=1);

namespace App\Enums;

enum EstadoPedidoVenta: string
{
    case PENDIENTE = 'pendiente';
    case COBRADO = 'cobrado';
    case CANCELADO = 'cancelado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PENDIENTE => 'Pendiente en caja',
            self::COBRADO => 'Cobrado',
            self::CANCELADO => 'Cancelado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDIENTE => 'warning',
            self::COBRADO => 'success',
            self::CANCELADO => 'gray',
        };
    }
}
