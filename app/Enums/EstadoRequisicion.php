<?php

declare(strict_types=1);

namespace App\Enums;

enum EstadoRequisicion: string
{
    case PENDIENTE_APROBACION = 'pendiente_aprobacion';
    case PENDIENTE = 'pendiente';
    case DESPACHADA = 'despachada';
    case RECIBIDA = 'recibida';
    case RECHAZADA = 'rechazada';
    case CANCELADA = 'cancelada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PENDIENTE_APROBACION => 'Pendiente de aprobación',
            self::PENDIENTE => 'Pendiente de despacho',
            self::DESPACHADA => 'En tránsito',
            self::RECIBIDA => 'Recibida',
            self::RECHAZADA => 'Rechazada',
            self::CANCELADA => 'Cancelada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDIENTE_APROBACION => 'gray',
            self::PENDIENTE => 'warning',
            self::DESPACHADA => 'info',
            self::RECIBIDA => 'success',
            self::RECHAZADA, self::CANCELADA => 'danger',
        };
    }
}
