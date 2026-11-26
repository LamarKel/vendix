<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Venta;
use Barryvdh\DomPDF\Facade\Pdf;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Symfony\Component\HttpFoundation\Response;

class VentaComprobanteController extends Controller
{
    /**
     * Genera el PDF del comprobante. Solo lee datos ya guardados; no recalcula nada.
     *
     * Ruta fuera del panel de Filament: el scoping automático por tenant no aplica aquí (ver
     * BelongsToTenant de Filament), así que la pertenencia a la empresa se verifica a mano.
     */
    public function __invoke(Venta $venta): Response
    {
        abort_unless(request()->user()->perteneceAEmpresa($venta->empresa_id), 403);

        $venta->load('detalles.producto', 'cliente', 'user', 'empresa.configuracion');

        $pdf = Pdf::loadView('ventas.comprobante', [
            'venta' => $venta,
            'empresa' => $venta->empresa,
            // PNG en base64: dompdf renderiza <img> embebido de forma más confiable que SVG inline.
            'qrTimbre' => \App\Support\RepresentacionImpresa::qrBase64($venta),
            'leyendaFiscal' => \App\Support\RepresentacionImpresa::leyenda($venta),
        ]);

        return $pdf->stream("comprobante-{$venta->ncf}.pdf");
    }
}
