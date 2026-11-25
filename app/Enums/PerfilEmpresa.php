<?php

declare(strict_types=1);

namespace App\Enums;

enum PerfilEmpresa: string
{
    case FACTURACION = 'facturacion';
    case RETAIL = 'retail';

    public function etiqueta(): string
    {
        return match ($this) {
            self::FACTURACION => 'Facturación',
            self::RETAIL => 'Retail',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::FACTURACION => 'Facturación general, compras, inventario y cuentas',
            self::RETAIL => 'Punto de venta para ferreterías, minimarkets, colmados y tiendas: inventario, compras, crédito y e-CF',
        };
    }

    /** @return array<Modulo> */
    public function modulosPorDefecto(): array
    {
        $base = [
            Modulo::MAESTROS_CLIENTES,
            Modulo::MAESTROS_PROVEEDORES,
            Modulo::MAESTROS_PRODUCTOS,
            Modulo::MAESTROS_CATEGORIAS,
            Modulo::VENTAS_POS,
            Modulo::VENTAS_LISTADO,
            Modulo::VENTAS_CAJAS,
            Modulo::IMPRESORAS,
        ];

        return match ($this) {
            self::FACTURACION, self::RETAIL => [
                ...$base,
                Modulo::MAESTROS_DESCUENTOS,
                Modulo::MAESTROS_PROMOCIONES,
                Modulo::MAESTROS_LISTAS_PRECIO,
                Modulo::COTIZACIONES,
                Modulo::VENTAS_ARQUEO_CAJA,
                Modulo::VENTAS_PEDIDOS,
                Modulo::INVENTARIO_KARDEX,
                Modulo::INVENTARIO_MERMAS,
                Modulo::COMPRAS,
                Modulo::COMPRAS_PEDIDOS,
                Modulo::COMPRAS_STOCK_BAJO,
                Modulo::DEVOLUCIONES,
                Modulo::CUENTAS_POR_COBRAR,
                Modulo::CUENTAS_POR_PAGAR,
                Modulo::ECF_SECUENCIAS,
                Modulo::AUDITORIA,
            ],
        };
    }
}
