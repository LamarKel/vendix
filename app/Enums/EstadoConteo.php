<?php

declare(strict_types=1);

namespace App\Enums;

enum EstadoConteo: string
{
    case EN_CONTEO = 'en_conteo';
    case EN_REVISION = 'en_revision';
    case APROBADO = 'aprobado';
    case CANCELADO = 'cancelado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::EN_CONTEO => 'Contando',
            self::EN_REVISION => 'En revisión',
            self::APROBADO => 'Aprobado (ajustado)',
            self::CANCELADO => 'Cancelado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::EN_CONTEO => 'warning',
            self::EN_REVISION => 'info',
            self::APROBADO => 'success',
            self::CANCELADO => 'gray',
        };
    }
}
