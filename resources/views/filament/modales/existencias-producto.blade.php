<div class="space-y-4">
  <div>
    <h4 class="text-sm font-semibold mb-2">{{ $datos['sucursal'] }} — por localidad</h4>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500"><th class="py-1">Localidad</th><th>Tipo</th><th class="text-right">Existencia</th></tr></thead>
      <tbody>
        @foreach ($datos['propia'] as $fila)
          <tr class="border-t border-gray-100 dark:border-white/10">
            <td class="py-1">{{ $fila['localidad'] }}</td>
            <td class="text-gray-500">{{ $fila['tipo'] }}</td>
            <td class="text-right {{ $fila['cantidad'] < 0 ? 'text-danger-600' : '' }}">{{ number_format($fila['cantidad'], 2) }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  @if (! empty($datos['otras']))
    <div>
      <h4 class="text-sm font-semibold mb-2">Otras sucursales (total)</h4>
      <table class="w-full text-sm">
        <tbody>
          @foreach ($datos['otras'] as $fila)
            <tr class="border-t border-gray-100 dark:border-white/10">
              <td class="py-1">{{ $fila['sucursal'] }}</td>
              <td class="text-right">{{ number_format($fila['total'], 2) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
      <p class="text-xs text-gray-500 mt-1">Para traer mercancía de otra sucursal, crea una requisición.</p>
    </div>
  @endif
</div>
