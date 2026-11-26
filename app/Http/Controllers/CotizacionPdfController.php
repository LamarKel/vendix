<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Cotizacion;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CotizacionPdfController extends Controller
{
    public function __invoke(Request $request, Cotizacion $cotizacion): Response
    {
        $user = $request->user();
        abort_unless($user && $user->perteneceAEmpresa($cotizacion->empresa_id), 403);
        abort_unless($user->can('cotizaciones.exportar'), 403);

        $cotizacion->load(['empresa.configuracion', 'cliente', 'detalles.producto', 'user']);

        // Los colores salen de la configuración de la empresa dentro de la vista (pdf._colores).
        $pdf = Pdf::loadView('cotizaciones.pdf', [
            'cotizacion' => $cotizacion,
            'empresa' => $cotizacion->empresa,
        ]);

        $pdf->setPaper('letter');

        return $pdf->stream("Cotizacion_{$cotizacion->numero}.pdf");
    }
}
