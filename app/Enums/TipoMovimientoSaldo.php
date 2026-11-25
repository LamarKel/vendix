<?php

declare(strict_types=1);

namespace App\Enums;

enum TipoMovimientoSaldo: string
{
    /** Vuelto que el cliente deja como saldo a favor (falta de menudos, decisión propia). */
    case DEPOSITO = 'deposito';

    /** Uso de saldo a favor para pagar (total o parcialmente) una compra/servicio. */
    case CONSUMO = 'consumo';

    /** Devolución en efectivo del saldo a favor al cliente. */
    case DEVOLUCION = 'devolucion';

    public function etiqueta(): string
    {
        return match ($this) {
            self::DEPOSITO => 'Depósito',
            self::CONSUMO => 'Consumo',
            self::DEVOLUCION => 'Devolución',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DEPOSITO => 'success',
            self::CONSUMO => 'info',
            self::DEVOLUCION => 'warning',
        };
    }

    /** El monto suma (+) o resta (−) del saldo del cliente. */
    public function esEntrada(): bool
    {
        return $this === self::DEPOSITO;
    }
}
