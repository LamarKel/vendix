<?php

declare(strict_types=1);

namespace App\Enums;

enum TipoConteo: string
{
    case TOTAL = 'total';
    case SELECCION = 'seleccion';
    case CICLICO = 'ciclico';

    public function etiqueta(): string
    {
        return match ($this) {
            self::TOTAL => 'Total (toda la localidad)',
            self::SELECCION => 'Selección (productos o categoría)',
            self::CICLICO => 'Cíclico (propuesto por el sistema)',
        };
    }
}
