<x-filament-panels::page>
  <div class="space-y-6">
    <div class="flex flex-wrap items-end gap-3">
      <label class="text-sm">Desde <input type="date" class="fi-input rounded-lg border-gray-300" wire:model.live="desde"></label>
      <label class="text-sm">Hasta <input type="date" class="fi-input rounded-lg border-gray-300" wire:model.live="hasta"></label>
    </div>

    @php $senales = $this->senales(); @endphp
    @if (! empty($senales))
      <div class="space-y-2">
        @foreach ($senales as $s)
          <div class="rounded-lg border p-3 {{ $s['color'] === 'rojo' ? 'border-danger-300 bg-danger-50 dark:bg-danger-950/30' : 'border-warning-300 bg-warning-50 dark:bg-warning-950/30' }}">
            <p class="font-semibold text-sm">{{ $s['titulo'] }}</p>
            <p class="text-sm">{{ $s['detalle'] }}</p>
          </div>
        @endforeach
      </div>
    @else
      <p class="text-sm text-gray-500">Sin señales que pasen los umbrales en este período.</p>
    @endif

    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 dark:bg-white/5 text-left">
          <tr>
            <th class="p-2">Persona</th>
            <th class="p-2 text-right">Anulaciones</th>
            <th class="p-2 text-right">Devoluciones</th>
            <th class="p-2 text-right">Descuentos (RD$)</th>
            <th class="p-2 text-right">Faltante de caja (RD$)</th>
            <th class="p-2 text-right">Ajustes y mermas (RD$)</th>
          </tr>
        </thead>
        <tbody>
          @forelse ($this->ranking() as $f)
            <tr class="border-t border-gray-100 dark:border-white/10">
              <td class="p-2">{{ $f['usuario'] }}</td>
              <td class="p-2 text-right">{{ $f['anulaciones'] }}</td>
              <td class="p-2 text-right">{{ $f['devoluciones'] }}</td>
              <td class="p-2 text-right">{{ number_format($f['descuentos'], 2) }}</td>
              <td class="p-2 text-right {{ $f['faltante_caja'] > 0 ? 'text-danger-600 font-semibold' : '' }}">{{ number_format($f['faltante_caja'], 2) }}</td>
              <td class="p-2 text-right">{{ number_format($f['ajustes'], 2) }}</td>
            </tr>
          @empty
            <tr><td colspan="6" class="p-3 text-center text-gray-500">Sin movimientos en el período.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <p class="text-xs text-gray-500">Una señal no acusa a nadie: indica dónde revisar. Compara con el volumen de ventas de cada persona.</p>
  </div>
</x-filament-panels::page>
