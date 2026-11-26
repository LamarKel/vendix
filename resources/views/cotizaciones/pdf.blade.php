@php extract(\App\Support\EstiloPdf::para($empresa)); @endphp
@php
    // Textos configurables en Configuración → Facturación → Textos en documentos impresos.
    $cfg = $empresa->config();
    $docCot = \App\Enums\DocumentoImpreso::COTIZACION;
    $observaciones = $cotizacion->notas ?: $cfg->textoDocumento($docCot, 'observaciones');
    $incluye = $cfg->textoDocumento($docCot, 'incluye');
    $noIncluye = $cfg->textoDocumento($docCot, 'no_incluye');
    $pie = $cfg->textoDocumento($docCot);
    $descuentoLineas = $cotizacion->detalles->sum(fn ($d) => (float) $d->descuento);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Cotización {{ $cotizacion->numero }}</title>
    @include('pdf._estilos')
</head>
<body>
    <div class="pie">
        <table><tr>
            <td>{{ $nombreEmpresaPdf }} · Cotización {{ $cotizacion->numero }}</td>
            <td style="text-align: right;">Generado con Vendix</td>
        </tr></table>
    </div>

    @include('pdf._encabezado', [
        'titulo' => 'COTIZACIÓN',
        'numero' => $cotizacion->numero,
        'datos' => [
            'Fecha' => $cotizacion->fecha->format('d/m/Y'),
            'Válida hasta' => $cotizacion->fecha_vencimiento?->format('d/m/Y'),
            'Condiciones' => $cotizacion->condiciones_pago,
            'Preparada por' => $cotizacion->user?->name,
        ],
    ])

    <table class="bloque">
        <tr>
            <td style="width: 58%; padding-right: 12px;">
                <p class="etiqueta">Preparada para</p>
                <div class="caja">
                    @if ($cotizacion->cliente)
                        <p class="fuerte">{{ $cotizacion->cliente->nombre }}</p>
                        @if ($cotizacion->cliente->documento)<p class="emp-dato">RNC / Cédula: {{ $cotizacion->cliente->documento }}</p>@endif
                        @if ($cotizacion->cliente->direccion)<p class="emp-dato">{{ $cotizacion->cliente->direccion }}</p>@endif
                        @if ($cotizacion->cliente->telefono || $cotizacion->cliente->email)
                            <p class="emp-dato">{{ collect([$cotizacion->cliente->telefono ? 'Tel: '.$cotizacion->cliente->telefono : null, $cotizacion->cliente->email])->filter()->implode(' · ') }}</p>
                        @endif
                    @else
                        <p class="fuerte">Cliente general</p>
                    @endif
                </div>
            </td>
            <td style="width: 42%;">
                <p class="etiqueta">Moneda</p>
                <div class="caja"><p class="fuerte">Pesos dominicanos (RD$)</p></div>
            </td>
        </tr>
    </table>

    <table class="lineas">
        <thead>
            <tr>
                <th style="width: 6%;">#</th>
                <th>Descripción</th>
                <th class="num" style="width: 10%;">Cant.</th>
                <th class="num" style="width: 14%;">Precio</th>
                @if ($descuentoLineas > 0)<th class="num" style="width: 12%;">Desc.</th>@endif
                <th class="num" style="width: 16%;">Importe</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($cotizacion->detalles as $detalle)
                <tr class="{{ $loop->even ? 'alt' : '' }}">
                    <td class="codigo">{{ $loop->iteration }}</td>
                    <td>
                        <b style="color: {{ $cs }};">{{ $detalle->producto?->nombre }}</b>
                        @if ($detalle->producto?->descripcion)<br><span style="color: #6B7280;">{{ $detalle->producto->descripcion }}</span>@endif
                        @if ($detalle->producto?->codigo)<br><span class="codigo">{{ $detalle->producto->codigo }}</span>@endif
                    </td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $detalle->cantidad, 3, '.', ''), '0'), '.') }}</td>
                    <td class="num">{{ number_format((float) $detalle->precio_unitario, 2) }}</td>
                    @if ($descuentoLineas > 0)<td class="num">{{ (float) $detalle->descuento > 0 ? '-'.number_format((float) $detalle->descuento, 2) : '' }}</td>@endif
                    <td class="num"><b>{{ number_format((float) $detalle->subtotal, 2) }}</b></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="resumen">
        <tr>
            <td style="width: 58%; padding-right: 12px;">
                @if ($observaciones)
                    <p class="etiqueta">Observaciones</p>
                    <div class="caja" style="font-size: 8.5px; color: #4B5563;">{!! nl2br(e($observaciones)) !!}</div>
                @endif
            </td>
            <td style="width: 42%;">
                <table class="totales">
                    <tr><td>Subtotal</td><td class="num">{{ number_format((float) $cotizacion->subtotal, 2) }}</td></tr>
                    @if ((float) $cotizacion->descuento > 0)
                        <tr><td>Descuento</td><td class="num">-{{ number_format((float) $cotizacion->descuento, 2) }}</td></tr>
                    @endif
                    <tr><td>ITBIS</td><td class="num">{{ number_format((float) $cotizacion->itbis, 2) }}</td></tr>
                    <tr class="gran"><td>TOTAL RD$</td><td class="num">{{ number_format((float) $cotizacion->total, 2) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    @if ($incluye || $noIncluye)
        <table class="bloque">
            <tr>
                @if ($incluye)
                    <td style="padding-right: 6px;">
                        <p class="etiqueta">Incluye</p>
                        <div class="caja" style="font-size: 8.5px;">{!! nl2br(e($incluye)) !!}</div>
                    </td>
                @endif
                @if ($noIncluye)
                    <td style="padding-left: 6px;">
                        <p class="etiqueta">No incluye</p>
                        <div class="caja" style="font-size: 8.5px;">{!! nl2br(e($noIncluye)) !!}</div>
                    </td>
                @endif
            </tr>
        </table>
    @endif

    <p class="nota" style="text-align: center; margin-top: 16px;">
        Precios en pesos dominicanos{{ $cotizacion->fecha_vencimiento ? ', válidos hasta el '.$cotizacion->fecha_vencimiento->format('d/m/Y') : '' }}. Sujeto a disponibilidad de inventario.
    </p>
    @if ($pie)
        <p class="nota" style="text-align: center; margin-top: 4px;">{!! nl2br(e($pie)) !!}</p>
    @endif
</body>
</html>
