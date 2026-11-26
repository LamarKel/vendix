{{-- Parámetros: $titulo, $numero, $datos (array etiqueta => valor) --}}
<div class="franja"></div>
<table class="encabezado">
    <tr>
        <td style="width: 58%; padding-right: 12px;">
            @if ($logoPdf)<img src="{{ $logoPdf }}" class="logo" alt=""><br>@endif
            <p class="emp-nombre">{{ $nombreEmpresaPdf }}</p>
            @if ($empresa->nombre_comercial && $empresa->nombre_comercial !== $empresa->razon_social)
                <p class="emp-dato">{{ $empresa->razon_social }}</p>
            @endif
            @if ($empresa->rnc)<p class="emp-dato">RNC: {{ $empresa->rnc }}</p>@endif
            @if ($empresa->direccion)<p class="emp-dato">{{ $empresa->direccion }}</p>@endif
            @if ($empresa->telefono || $empresa->email)
                <p class="emp-dato">{{ collect([$empresa->telefono ? 'Tel: '.$empresa->telefono : null, $empresa->email])->filter()->implode(' · ') }}</p>
            @endif
        </td>
        <td style="width: 42%;">
            <div class="doc-caja">
                <p class="doc-titulo">{{ $titulo }}</p>
                <p class="doc-numero">{{ $numero }}</p>
                @foreach ($datos as $etiqueta => $valor)
                    @if (filled($valor))<p class="doc-dato">{{ $etiqueta }}: <b>{{ $valor }}</b></p>@endif
                @endforeach
            </div>
        </td>
    </tr>
</table>
