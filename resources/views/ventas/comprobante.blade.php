@php extract(\App\Support\EstiloPdf::para($empresa)); @endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Comprobante {{ $venta->ncf }}</title>
    @include('pdf._estilos')
</head>
<body>
    <div class="pie">
        <table><tr>
            <td>{{ $nombreEmpresaPdf }} · {{ $venta->ncf }}</td>
            <td style="text-align: right;">Generado con Vendix</td>
        </tr></table>
    </div>

    @if ($venta->estaAnulada())
        <div class="anulada">COMPROBANTE ANULADO</div>
    @endif

    @include('pdf._encabezado', [
        'titulo' => mb_strtoupper($venta->etiquetaComprobante()),
        'numero' => $venta->ncf,
        'datos' => [
            'Fecha' => $venta->fecha->format('d/m/Y h:i A'),
            'Forma de pago' => $venta->forma_pago?->etiqueta(),
            'Atendido por' => $venta->user?->name,
        ],
    ])

    <table class="bloque">
        <tr>
            <td style="width: 58%; padding-right: 12px;">
                <p class="etiqueta">Facturado a</p>
                <div class="caja">
                    <p class="fuerte">{{ $venta->nombreCliente() }}</p>
                    @if ($venta->cliente?->documento)<p class="emp-dato">RNC / Cédula: {{ $venta->cliente->documento }}</p>@endif
                    @if ($venta->cliente?->direccion)<p class="emp-dato">{{ $venta->cliente->direccion }}</p>@endif
                    @if ($venta->cliente?->telefono)<p class="emp-dato">Tel: {{ $venta->cliente->telefono }}</p>@endif
                </div>
            </td>
            <td style="width: 42%;">
                <p class="etiqueta">Moneda</p>
                <div class="caja"><p class="fuerte">{{ $venta->moneda === 'DOP' ? 'Pesos dominicanos (RD$)' : $venta->moneda }}</p></div>
            </td>
        </tr>
    </table>

    <table class="lineas">
        <thead>
            <tr>
                <th style="width: 6%;">#</th>
                <th>Descripción</th>
                <th class="num" style="width: 11%;">Cant.</th>
                <th class="num" style="width: 14%;">Precio</th>
                <th class="num" style="width: 12%;">ITBIS</th>
                <th class="num" style="width: 15%;">Importe</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($venta->detalles as $detalle)
                <tr class="{{ $loop->even ? 'alt' : '' }}">
                    <td class="codigo">{{ $loop->iteration }}</td>
                    <td>
                        {{ $detalle->descripcion }}
                        @if ($detalle->producto?->codigo)<br><span class="codigo">{{ $detalle->producto->codigo }}</span>@endif
                    </td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $detalle->cantidad, 3, '.', ''), '0'), '.') }}</td>
                    <td class="num">{{ number_format((float) $detalle->precio_unitario, 2) }}</td>
                    <td class="num">{{ number_format((float) $detalle->itbis_monto, 2) }}</td>
                    <td class="num"><b>{{ number_format((float) $detalle->subtotal, 2) }}</b></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="resumen">
        <tr>
            <td style="width: 58%; padding-right: 12px;">
                @if ($leyendaFiscal ?? null)
                    <div class="aviso" style="margin-top: 0;">{{ $leyendaFiscal }}</div>
                @endif

                @if ($qrTimbre)
                    <table style="margin-top: 8px;">
                        <tr>
                            <td style="width: 92px;"><img src="data:image/png;base64,{{ $qrTimbre }}" style="width: 86px; height: 86px;" alt="QR"></td>
                            <td style="padding-left: 8px; font-size: 8px; color: #4B5563; vertical-align: middle;">
                                @if ($venta->codigo_seguridad)<p>Código de seguridad: <b style="color: {{ $cs }};">{{ $venta->codigo_seguridad }}</b></p>@endif
                                @if ($venta->ecf_fecha_firma)<p style="margin-top: 2px;">Fecha de firma digital: {{ $venta->ecf_fecha_firma->format('d/m/Y H:i:s') }}</p>@endif
                                <p style="margin-top: 2px;">Escanee el código para validar este e-CF en la DGII.</p>
                                @if ($venta->dgii_url)<p style="margin-top: 2px; font-size: 6.5px; color: #9CA3AF; word-break: break-all;">{{ $venta->dgii_url }}</p>@endif
                            </td>
                        </tr>
                    </table>
                @endif
            </td>
            <td style="width: 42%;">
                <table class="totales">
                    <tr><td>Subtotal</td><td class="num">{{ number_format((float) $venta->subtotalNeto(), 2) }}</td></tr>
                    @if ((float) $venta->descuento > 0)
                        <tr><td>Descuento (incluido en líneas)</td><td class="num">-{{ number_format((float) $venta->descuento, 2) }}</td></tr>
                    @endif
                    @if ((float) $venta->monto_gravado_0 > 0)
                        <tr><td>Gravado 0%</td><td class="num">{{ number_format((float) $venta->monto_gravado_0, 2) }}</td></tr>
                    @endif
                    @if ((float) $venta->monto_exento > 0)
                        <tr><td>Monto exento</td><td class="num">{{ number_format((float) $venta->monto_exento, 2) }}</td></tr>
                    @endif
                    @if ((float) $venta->itbis_18 > 0)
                        <tr><td>ITBIS 18%</td><td class="num">{{ number_format((float) $venta->itbis_18, 2) }}</td></tr>
                    @endif
                    @if ((float) $venta->itbis_16 > 0)
                        <tr><td>ITBIS 16%</td><td class="num">{{ number_format((float) $venta->itbis_16, 2) }}</td></tr>
                    @endif
                    <tr class="gran"><td>TOTAL {{ $venta->moneda === 'DOP' ? 'RD$' : $venta->moneda }}</td><td class="num">{{ number_format((float) $venta->total, 2) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    @php($pie = $empresa->config()->textoDocumento(\App\Enums\DocumentoImpreso::VENTA_FACTURA))
    @if ($pie)
        <p class="nota" style="text-align: center; margin-top: 18px;">{!! nl2br(e($pie)) !!}</p>
    @endif

    @if ($venta->estaAnulada())
        <p class="nota" style="text-align: center; color: #DC2626;">
            Anulado{{ $venta->anulada_en ? ' el '.$venta->anulada_en->format('d/m/Y H:i') : '' }}.
            @if ($venta->motivo_anulacion) Motivo: {{ $venta->motivo_anulacion }} @endif
        </p>
    @endif
</body>
</html>
