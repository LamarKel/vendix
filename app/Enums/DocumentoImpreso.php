<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Empresa;

/**
 * Catálogo de documentos impresos/PDF cuyos textos configura cada empresa en
 * Configuración → Facturación → "Textos en documentos" (columna JSON
 * empresa_configuracion.textos_documentos). Única fuente de verdad: para sumar un documento
 * nuevo, agrega su caso aquí y lee el texto en su vista con
 * $empresa->config()->textoDocumento(DocumentoImpreso::X).
 *
 * Cada documento tiene al menos el campo 'pie'; algunos tienen campos extra (camposExtra()).
 */
enum DocumentoImpreso: string
{
    case VENTA_FACTURA = 'venta_factura';
    case VENTA_TICKET = 'venta_ticket';
    case COTIZACION = 'cotizacion';
    case ORDEN_COMPRA = 'orden_compra';
    case PEDIDO_COMPRA = 'pedido_compra';

    public function etiqueta(): string
    {
        return match ($this) {
            self::VENTA_FACTURA => 'Factura de venta (PDF)',
            self::VENTA_TICKET => 'Ticket de venta (POS)',
            self::COTIZACION => 'Cotización (PDF)',
            self::ORDEN_COMPRA => 'Orden de compra (PDF)',
            self::PEDIDO_COMPRA => 'Pedido de compra (PDF)',
        };
    }

    /** Módulo que debe estar activo para que el documento aparezca en la configuración. */
    public function modulo(): Modulo
    {
        return match ($this) {
            self::VENTA_FACTURA => Modulo::VENTAS_LISTADO,
            self::VENTA_TICKET => Modulo::VENTAS_POS,
            self::COTIZACION => Modulo::COTIZACIONES,
            self::ORDEN_COMPRA => Modulo::COMPRAS_PEDIDOS,
            self::PEDIDO_COMPRA => Modulo::COMPRAS,
        };
    }

    public function disponiblePara(Empresa $empresa): bool
    {
        return $empresa->tieneModulo($this->modulo());
    }

    /**
     * Campos además de 'pie': clave => etiqueta.
     *
     * @return array<string, string>
     */
    public function camposExtra(): array
    {
        return match ($this) {
            self::COTIZACION => [
                'incluye' => 'Incluye',
                'no_incluye' => 'No incluye',
                'observaciones' => 'Observaciones por defecto (si la cotización no trae notas)',
            ],
            default => [],
        };
    }

    /** @return array<int, string> */
    public function campos(): array
    {
        return ['pie', ...array_keys($this->camposExtra())];
    }

    /**
     * Texto que sale mientras la empresa no haya configurado ese campo (los textos que antes
     * estaban fijos en las vistas). Si la empresa lo deja en blanco a propósito, no sale nada.
     */
    public function textoPorDefecto(string $campo = 'pie'): ?string
    {
        return match ([$this, $campo]) {
            [self::VENTA_TICKET, 'pie'] => '¡Gracias por su compra!',
            [self::COTIZACION, 'pie'] => 'Gracias por su preferencia.',
            // Incluye / No incluye quedan vacíos por defecto: son para negocios que cotizan
            // servicios; una tienda los llena solo si los necesita.
            [self::COTIZACION, 'observaciones'] => 'Precios sujetos a cambio sin previo aviso después de la fecha de vigencia.',
            default => null,
        };
    }

    public function ayudaPie(): string
    {
        return match ($this) {
            self::VENTA_TICKET => 'Se imprime al final del ticket. Ej.: "No se aceptan devoluciones después de 30 días."',
            default => 'Se imprime al final del documento (políticas, garantía, cuentas bancarias, etc.).',
        };
    }
}
