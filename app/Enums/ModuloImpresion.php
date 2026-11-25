<?php

namespace App\Enums;

enum ModuloImpresion: string
{
    case FACTURACION = 'facturacion';
    case REPORTES = 'reportes';

    public function etiqueta(): string
    {
        return match ($this) {
            self::FACTURACION => 'Facturación',
            self::REPORTES => 'Reportes',
        };
    }
}
