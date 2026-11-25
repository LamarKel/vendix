<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\EstadoFiscal;
use App\Enums\TipoComprobante;
use App\Models\Venta;

/**
 * Reglas de la Representación Impresa (RI) de un e-CF:
 *
 * - El QR y el código de seguridad salen SIEMPRE que el e-CF esté firmado (tiene código de
 *   seguridad), esté o no aceptado todavía por la DGII: la RI se entrega en el momento de la venta
 *   y el cliente la valida después escaneando el QR.
 * - Mientras la DGII no lo acepte, sale la leyenda oficial de contingencia (72 horas).
 * - Un e-CF rechazado no lleva QR: no tiene validez fiscal y hay que corregirlo.
 * - La URL del QR es la que devuelve el PAC; si no la trae, se arma con el formato de consulta de
 *   timbre de la DGII (ConsultaTimbre / ConsultaTimbreFC para consumo menor de RD$250,000).
 */
class RepresentacionImpresa
{
    public const LEYENDA_CONTINGENCIA = 'e-CF emitido en modalidad de Contingencia, el cual podrá ser consultado para su validez fiscal, a partir de las setenta y dos (72) horas.';

    public const UMBRAL_CONSUMO = 250000;

    public static function urlTimbre(Venta $venta): ?string
    {
        if (! $venta->esElectronica() || $venta->estado_fiscal === EstadoFiscal::RECHAZADO) {
            return null;
        }

        if (filled($venta->dgii_url)) {
            return $venta->dgii_url;
        }

        if (blank($venta->codigo_seguridad) || blank($venta->ncf)) {
            return null; // aún sin firmar: no hay timbre que consultar
        }

        $ambiente = strtolower((string) ($venta->ambiente?->value ?? $venta->ambiente ?? 'eCF'));
        $rncEmisor = preg_replace('/\D/', '', (string) $venta->empresa?->rnc);
        $total = number_format((float) $venta->total, 2, '.', '');
        $codigo = rawurlencode((string) $venta->codigo_seguridad);

        if ($venta->tipo_comprobante === TipoComprobante::FACTURA_CONSUMO && (float) $venta->total < self::UMBRAL_CONSUMO) {
            return "https://fc.dgii.gov.do/{$ambiente}/ConsultaTimbreFC?RncEmisor={$rncEmisor}&ENCF={$venta->ncf}&MontoTotal={$total}&CodigoSeguridad={$codigo}";
        }

        $rncComprador = preg_replace('/\D/', '', (string) $venta->cliente?->documento);
        $fechaEmision = $venta->fecha?->format('d-m-Y');
        $fechaFirma = rawurlencode((string) $venta->ecf_fecha_firma?->format('d-m-Y H:i:s'));

        return "https://ecf.dgii.gov.do/{$ambiente}/ConsultaTimbre?RncEmisor={$rncEmisor}&RncComprador={$rncComprador}&ENCF={$venta->ncf}"
            ."&FechaEmision={$fechaEmision}&MontoTotal={$total}&FechaFirma={$fechaFirma}&CodigoSeguridad={$codigo}";
    }

    /** Leyenda a imprimir debajo del total (null = ninguna). */
    public static function leyenda(Venta $venta): ?string
    {
        if (! $venta->esElectronica() || $venta->estado_fiscal?->esAceptado()) {
            return null;
        }

        if ($venta->estado_fiscal === EstadoFiscal::RECHAZADO) {
            return 'e-CF RECHAZADO por la DGII: este documento no tiene validez fiscal. Solicite su corrección.';
        }

        return self::LEYENDA_CONTINGENCIA;
    }

    /** PNG en base64 para las vistas (dompdf renderiza mejor <img> embebido que SVG). */
    public static function qrBase64(Venta $venta, int $tamano = 120): ?string
    {
        $url = self::urlTimbre($venta);

        return $url === null ? null : base64_encode((string) \SimpleSoftwareIO\QrCode\Facades\QrCode::format('png')->size($tamano)->generate($url));
    }
}
