@use('Illuminate\Support\Facades\Storage')
<x-filament-panels::page>
  <div
    class="pos-screen"
    x-data="{ highlightProducto: -1, highlightCliente: -1 }"
    x-on:producto-escaneado.window="$nextTick(() => { highlightProducto = -1; $refs.buscadorProducto?.focus(); })"
  >
    <div class="pos-grid">
      <div class="pos-main">
        {{-- Cliente del pedido --}}
        <div class="card">
          <h3 class="card-title">¿A nombre de quién?</h3>
          <input
            type="text"
            class="form-input"
            maxlength="120"
            placeholder="Nombre del cliente (ej.: Juan Pérez)"
            wire:model.blur="nombreCliente"
            autofocus
          />

          @if ($this->clienteSeleccionado())
            <div class="flex items-center justify-between mt-2">
              <p class="pos-muted">Cliente registrado: <strong>{{ $this->clienteSeleccionado()->nombre }}</strong></p>
              <button type="button" class="btn btn-secondary" wire:click="quitarCliente">Quitar</button>
            </div>
          @else
            <details class="mt-2">
              <summary class="pos-muted" style="cursor:pointer;">Vincular a un cliente registrado (opcional, para crédito o lista de precio)</summary>
              <input
                type="text"
                class="form-input mt-2"
                placeholder="Buscar cliente por nombre o documento..."
                wire:model.live.debounce.300ms="busquedaCliente"
              />
              @if ($busquedaCliente !== '')
                <ul class="pos-resultados pos-resultados-cliente">
                  @forelse ($this->clientesSugeridos() as $cliente)
                    <li>
                      <button type="button" wire:click="seleccionarCliente({{ $cliente->id }})">
                        <span>{{ $cliente->nombre }}</span>
                        @if ($cliente->documento)
                          <span class="pos-muted">{{ $cliente->documento }}</span>
                        @endif
                      </button>
                    </li>
                  @empty
                    <li class="pos-vacio">Sin resultados.</li>
                  @endforelse
                </ul>
              @endif
            </details>
          @endif
        </div>

        {{-- Buscar producto --}}
        <div class="card">
          <h3 class="card-title">Productos</h3>
          <input
            type="text"
            class="form-input"
            x-ref="buscadorProducto"
            placeholder="Buscar producto o escanear código..."
            wire:model.live.debounce.300ms="busquedaProducto"
            wire:keydown.enter="escanearOBuscar($event.target.value)"
          />

          @if ($busquedaProducto !== '')
            <ul class="pos-resultados pos-resultados-producto">
              @forelse ($this->resultadosBusqueda() as $fila)
                <li>
                  @if ($fila['tipo'] === 'pesado')
                    <div class="flex items-center justify-between gap-2 pos-resultado-pesado" x-data="{ peso: '1.000' }">
                      <span>{{ $fila['etiqueta'] }} <span class="pos-muted">({{ $fila['precio_texto'] }})</span></span>
                      <span class="flex items-center gap-2">
                        <input type="number" min="0.001" step="0.001" class="form-input pos-input-sm" x-model="peso" />
                        <button type="button" class="btn btn-secondary pos-nowrap" x-on:click="$wire.agregarProductoPorPeso({{ $fila['producto_id'] }}, peso)">Agregar</button>
                      </span>
                    </div>
                  @else
                    <button
                      type="button"
                      wire:click="{{ $fila['presentacion_id'] ? 'agregarPresentacion('.$fila['presentacion_id'].')' : 'agregarProducto('.$fila['producto_id'].')' }}"
                    >
                      <span class="flex items-center gap-2">
                        @if ($fila['imagen'])
                          <img src="{{ Storage::disk('public')->url($fila['imagen']) }}" alt="" class="pos-thumb" />
                        @endif
                        {{ $fila['etiqueta'] }}
                      </span>
                      <span class="flex items-center gap-2 pos-muted">
                        {{ $fila['precio_texto'] }}
                        @if ($fila['controla_stock'])
                          <span class="badge {{ $fila['stock'] > 0 ? 'badge-success' : 'badge-danger' }}">stock {{ number_format($fila['stock'], 2) }}</span>
                        @endif
                      </span>
                    </button>
                  @endif
                </li>
              @empty
                <li class="pos-vacio">Sin resultados.</li>
              @endforelse
            </ul>
          @endif
        </div>

        {{-- Pedido --}}
        <div class="card">
          <h3 class="card-title">Pedido</h3>
          <div class="overflow-x-auto">
            <table class="table">
              <thead>
                <tr>
                  <th>Descripción</th>
                  <th class="pos-num">Cantidad</th>
                  <th class="pos-num">Precio</th>
                  <th class="pos-num">Subtotal</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                @forelse ($carrito as $indice => $linea)
                  <tr wire:key="pedido-linea-{{ $indice }}">
                    <td>
                      {{ $linea['codigo'] }} — {{ $linea['nombre'] }}
                      @if ($this->lineaConStockInsuficiente($linea))
                        <div class="mt-1"><span class="badge badge-danger">Stock insuficiente (disponible {{ number_format((float) $this->stockDeLinea($linea), 2) }})</span></div>
                      @endif
                    </td>
                    <td class="pos-num">
                      <input
                        type="number"
                        min="{{ $linea['tipo_venta'] === 'pesado' ? '0.001' : '1' }}"
                        step="{{ $linea['tipo_venta'] === 'pesado' ? '0.001' : '1' }}"
                        class="form-input"
                        wire:model.live.debounce.400ms="carrito.{{ $indice }}.cantidad"
                      />
                    </td>
                    <td class="pos-num">RD$ {{ number_format((float) $linea['precio_unitario'], 2) }}</td>
                    <td class="pos-num">RD$ {{ number_format((float) $this->subtotalLinea($linea), 2) }}</td>
                    <td><button type="button" class="btn btn-danger" wire:click="quitarLinea({{ $indice }})">Quitar</button></td>
                  </tr>
                @empty
                  <tr><td colspan="5" class="text-center pos-muted">Busca productos arriba para armar el pedido.</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="pos-side">
        <div class="card pos-totales">
          <h3 class="card-title">Enviar a caja</h3>

          <label class="form-label">Notas para caja (opcional)</label>
          <textarea class="form-input" rows="2" maxlength="500" wire:model.blur="notasPedido" placeholder="Ej.: lo recoge en el almacén"></textarea>

          <div class="pos-total-final mt-2">
            <span class="pos-total-label">Total estimado</span>
            <span class="pos-total-valor">RD$ {{ number_format((float) ($totales['total'] ?? 0), 2) }}</span>
          </div>
          <p class="pos-muted" style="font-size:0.8rem;">El precio final y el comprobante se definen en caja.</p>

          <button
            type="button"
            class="btn btn-cobrar-caja mt-2"
            wire:click="enviarACaja"
            wire:loading.attr="disabled"
            @disabled(empty($carrito))
          >
            Enviar a caja
          </button>
        </div>
      </div>
    </div>
  </div>
</x-filament-panels::page>
