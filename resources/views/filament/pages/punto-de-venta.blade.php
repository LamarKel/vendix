@use('Illuminate\Support\Facades\Storage')
{{--
  POS de mostrador (teclado + lector). Distribución tomada de los POS de caja más comunes en RD
  (Alegra POS, Eleventa, los sistemas de supermercado): lector arriba, carrito como protagonista,
  total grande a la derecha y todo el flujo operable con teclas de función, sin mouse.
    F2 producto · F3 pedidos · F4 cliente · F7 comprobante · F12 cobrar · Esc cerrar
    ↑/↓ + Enter en los resultados · en el cobro: Enter o F12 confirma.
--}}
<x-filament-panels::page>
  <style>
    .pos-screen { --vx-total-bg: #0B1B2B; display: flex; flex-direction: column; gap: .75rem; }
    .pos-screen .vx-card { background: #fff; border: 1px solid #E5E7EB; border-radius: 12px; padding: .9rem 1rem; }
    .pos-screen .vx-titulo { font-size: .75rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: #6B7280; margin-bottom: .45rem; display: flex; justify-content: space-between; align-items: center; }
    .pos-screen kbd { display: inline-block; min-width: 1.6rem; padding: 0 .35rem; border: 1px solid #D1D5DB; border-bottom-width: 2px; border-radius: 5px; background: #F9FAFB; font: 600 .7rem/1.35rem Inter, system-ui, sans-serif; color: #374151; text-align: center; }
    .pos-screen .vx-num { font-variant-numeric: tabular-nums; text-align: right; white-space: nowrap; }
    .pos-screen .vx-muted { color: #6B7280; }
    .pos-screen .vx-alerta { color: #B91C1C; font-size: .85rem; }

    .pos-screen .vx-barra { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1rem; font-size: .85rem; }
    .pos-screen .vx-barra .vx-atajos { margin-left: auto; display: flex; flex-wrap: wrap; gap: .6rem; color: #6B7280; }

    .pos-grid { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: .75rem; align-items: start; }
    .pos-main, .pos-side { display: flex; flex-direction: column; gap: .75rem; min-width: 0; }
    .pos-side { position: sticky; top: .75rem; }
    @media (max-width: 1100px) { .pos-grid { grid-template-columns: 1fr; } .pos-side { position: static; } }

    .pos-screen .vx-scan { position: relative; }
    .pos-screen .vx-scan input { width: 100%; height: 56px; font-size: 1.2rem; padding: 0 1rem 0 2.9rem; border: 2px solid var(--primary-600); border-radius: 12px; outline: none; }
    .pos-screen .vx-scan input:focus { box-shadow: 0 0 0 4px var(--primary-100); }
    .pos-screen .vx-scan .vx-lupa { position: absolute; left: .9rem; top: 16px; width: 24px; height: 24px; color: var(--primary-600); }
    .pos-screen .vx-lista { position: absolute; z-index: 20; left: 0; right: 0; top: 60px; max-height: 55vh; overflow-y: auto; background: #fff; border: 1px solid #E5E7EB; border-radius: 12px; box-shadow: 0 18px 40px rgba(15, 23, 42, .18); padding: .3rem; }
    .pos-screen .vx-lista li > button, .pos-screen .vx-lista li > div { width: 100%; display: flex; justify-content: space-between; align-items: center; gap: .75rem; padding: .55rem .7rem; border-radius: 8px; text-align: left; }
    .pos-screen .vx-lista li > button:hover, .pos-screen .vx-lista li.vx-hl > * { background: var(--primary-50); }
    .pos-screen .vx-lista li.vx-hl > * { outline: 2px solid var(--primary-600); outline-offset: -2px; }
    .pos-screen .vx-thumb { width: 36px; height: 36px; border-radius: 6px; object-fit: cover; }

    .pos-screen .vx-carrito { padding: 0; overflow: hidden; }
    .pos-screen .vx-carrito table { width: 100%; border-collapse: collapse; }
    .pos-screen .vx-carrito th { background: #F9FAFB; font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: #6B7280; padding: .55rem .75rem; text-align: left; border-bottom: 1px solid #E5E7EB; }
    .pos-screen .vx-carrito td { padding: .5rem .75rem; border-bottom: 1px solid #F3F4F6; vertical-align: middle; }
    .pos-screen .vx-carrito tbody tr:nth-child(even) td { background: #FCFCFD; }
    .pos-screen .vx-carrito .vx-codigo { font-size: .75rem; color: #9CA3AF; }
    .pos-screen .vx-cant { display: inline-flex; align-items: center; border: 1px solid #D1D5DB; border-radius: 8px; overflow: hidden; }
    .pos-screen .vx-cant button { width: 32px; height: 34px; font-weight: 700; color: #374151; background: #F9FAFB; }
    .pos-screen .vx-cant button:hover { background: var(--primary-50); color: var(--primary-700); }
    .pos-screen .vx-cant input { width: 72px; height: 34px; border: 0; text-align: center; font-variant-numeric: tabular-nums; }
    .pos-screen .vx-quitar { color: #9CA3AF; padding: .35rem; border-radius: 6px; }
    .pos-screen .vx-quitar:hover { color: #DC2626; background: #FEF2F2; }
    .pos-screen .vx-vacio { padding: 3rem 1rem; text-align: center; color: #9CA3AF; }
    .pos-screen .vx-badge { display: inline-block; font-size: .68rem; font-weight: 700; padding: 1px 6px; border-radius: 999px; vertical-align: middle; }
    .pos-screen .vx-badge-ok { background: var(--primary-100); color: var(--primary-800); }
    .pos-screen .vx-badge-mal { background: #FEE2E2; color: #991B1B; }

    .pos-screen .vx-total { background: var(--vx-total-bg); color: #fff; border-radius: 14px; padding: 1rem 1.1rem; }
    .pos-screen .vx-total dl > div { display: flex; justify-content: space-between; font-size: .9rem; color: #CBD5E1; padding: .1rem 0; }
    .pos-screen .vx-total .vx-gran { display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; border-top: 1px solid rgba(255,255,255,.12); margin-top: .5rem; padding-top: .55rem; }
    .pos-screen .vx-total .vx-gran span:first-child { font-size: .8rem; letter-spacing: .08em; color: #94A3B8; }
    .pos-screen .vx-total .vx-gran .vx-num { font-size: 2.3rem; font-weight: 800; margin-left: auto; color: var(--primary-300); }
    .pos-screen .vx-total .vx-articulos { font-size: .8rem; color: #94A3B8; }
    .pos-screen .btn-cobrar { width: 100%; min-height: 60px; margin-top: .8rem; border-radius: 12px; background: var(--primary-600); color: #fff; font-size: 1.2rem; font-weight: 800; display: flex; justify-content: center; align-items: center; gap: .6rem; }
    .pos-screen .btn-cobrar:hover:not(:disabled) { background: var(--primary-700); }
    .pos-screen .btn-cobrar:disabled { opacity: .45; cursor: not-allowed; }
    .pos-screen .btn-cobrar kbd { background: rgba(255,255,255,.15); border-color: rgba(255,255,255,.3); color: #fff; }

    .pos-screen .vx-opciones { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: .4rem; }
    .pos-screen .vx-opcion { min-height: 44px; border: 1px solid #D1D5DB; border-radius: 10px; background: #fff; font-weight: 600; padding: .3rem .5rem; }
    .pos-screen .vx-opcion:hover { border-color: var(--primary-600); }
    .pos-screen .vx-opcion.vx-sel { background: var(--primary-600); border-color: var(--primary-600); color: #fff; }

    .pos-screen .vx-modal-fondo { position: fixed; inset: 0; z-index: 50; background: rgba(11, 27, 43, .6); display: grid; place-items: center; padding: 1rem; }
    .pos-screen .vx-modal { background: #fff; border-radius: 16px; width: min(620px, 100%); max-height: 92dvh; overflow-y: auto; box-shadow: 0 30px 80px rgba(0,0,0,.35); }
    .pos-screen .vx-modal-cab { background: var(--vx-total-bg); color: #fff; padding: 1rem 1.25rem; display: flex; justify-content: space-between; align-items: center; border-radius: 16px 16px 0 0; }
    .pos-screen .vx-modal-cab .vx-num { font-size: 2rem; font-weight: 800; color: var(--primary-300); }
    .pos-screen .vx-modal-cuerpo { padding: 1rem 1.25rem; display: flex; flex-direction: column; gap: 1rem; }
    .pos-screen .vx-efectivo input { width: 100%; height: 56px; font-size: 1.6rem; text-align: right; font-variant-numeric: tabular-nums; border: 2px solid var(--primary-600); border-radius: 12px; padding: 0 1rem; }
    .pos-screen .vx-billetes { display: grid; grid-template-columns: repeat(4, 1fr); gap: .4rem; margin-top: .45rem; }
    .pos-screen .vx-vuelto { display: flex; justify-content: space-between; align-items: baseline; background: var(--primary-50); border: 1px solid var(--primary-200); border-radius: 12px; padding: .7rem 1rem; font-weight: 700; color: var(--primary-800); }
    .pos-screen .vx-vuelto .vx-num { font-size: 1.8rem; }
    .pos-screen .vx-modal-pie { display: grid; grid-template-columns: 1fr 2fr; gap: .5rem; padding: 0 1.25rem 1.25rem; }
    .pos-screen .vx-modal-pie .btn-secundario { min-height: 56px; border-radius: 12px; border: 1px solid #D1D5DB; font-weight: 700; }
    .pos-screen .vx-modal-pie .btn-cobrar { margin-top: 0; min-height: 56px; }
  </style>

  <div
    class="pos-screen"
    x-data="{
      modalCobro: false,
      hlProducto: -1,
      hlCliente: -1,
      moverHl(lista, delta) {
        const items = this.$root.querySelectorAll('[data-lista=' + lista + '] > li[data-item]');
        if (!items.length) return;
        const clave = lista === 'producto' ? 'hlProducto' : 'hlCliente';
        this[clave] = (this[clave] + delta + items.length) % items.length;
        items[this[clave]].scrollIntoView({ block: 'nearest' });
      },
      elegirHl(lista) {
        const clave = lista === 'producto' ? 'hlProducto' : 'hlCliente';
        const item = this.$root.querySelectorAll('[data-lista=' + lista + '] > li[data-item]')[this[clave]];
        if (!item) return false;
        (item.querySelector('[data-elegir]') ?? item.querySelector('button'))?.click();
        this[clave] = -1;
        return true;
      },
      enfocar(ref) { this.$nextTick(() => { this.$refs[ref]?.focus(); this.$refs[ref]?.select?.(); }); },
      abrirCobro() { if (! this.$refs.btnCobrar?.disabled) { this.modalCobro = true; this.enfocar('efectivo'); } },
      confirmar() { const b = this.$refs.btnConfirmar; if (b && ! b.disabled) b.click(); },
    }"
    x-init="enfocar('buscadorProducto')"
    x-on:abrir-ticket.window="modalCobro = false; window.open($event.detail.url, '_blank'); enfocar('buscadorProducto')"
    x-on:producto-escaneado.window="hlProducto = -1; enfocar('buscadorProducto')"
    x-on:keydown.window="
      const k = $event.key;
      if (k === 'Escape') { if (modalCobro) { modalCobro = false; enfocar('buscadorProducto'); } return; }
      if (modalCobro) { if (k === 'F12' || (k === 'Enter' && $event.target.tagName !== 'BUTTON')) { $event.preventDefault(); confirmar(); } return; }
      const mapa = { F2: 'buscadorProducto', F3: 'buscadorPedido', F4: 'buscadorCliente', F7: 'tipoComprobante' };
      if (mapa[k]) { $event.preventDefault(); enfocar(mapa[k]); }
      if (k === 'F12') { $event.preventDefault(); abrirCobro(); }
    "
  >
    @if ($this->arqueoAbierto() === null)
      {{-- Sin caja abierta: se bloquea la pantalla de venta hasta abrirla --}}
      <div class="vx-card pos-apertura-caja" style="max-width:460px;margin:3rem auto;text-align:center" x-data="{ fondoInicial: '' }">
        <h3 style="font-size:1.4rem;font-weight:800">Abrir caja</h3>
        <p class="vx-muted">Debes abrir la caja con un fondo inicial antes de poder cobrar.</p>
        <div class="vx-efectivo" style="margin-top:1rem">
          <input type="number" min="0" step="0.01" placeholder="0.00" x-model="fondoInicial" x-init="$el.focus()"
            x-on:keydown.enter="$wire.abrirCaja(fondoInicial || '0')" aria-label="Fondo inicial" />
        </div>
        <button type="button" class="btn-cobrar" x-on:click="$wire.abrirCaja(fondoInicial || '0')">Abrir caja <kbd>Enter</kbd></button>
      </div>
    @else
      @php
        $arqueo = $this->arqueoAbierto();
        $articulos = collect($carrito)->sum(fn ($l) => ($l['tipo_venta'] ?? null) === 'pesado' ? 1 : (float) $l['cantidad']);
      @endphp

      {{-- Barra de estado: caja + atajos (el cierre se hace desde Arqueos de Caja) --}}
      <div class="vx-card vx-barra pos-estado-caja" style="padding:.55rem 1rem">
        <span class="vx-badge vx-badge-ok">Caja abierta</span>
        <span class="vx-muted">
          {{ $arqueo->user?->name ?? 'Usuario' }} · desde {{ $arqueo->abierto_en->format('d/m/Y H:i') }} ·
          fondo RD$ {{ number_format((float) $arqueo->fondo_inicial, 2) }}
        </span>
        <span class="vx-atajos">
          <span><kbd>F2</kbd> Producto</span>
          @if ($this->puedeCobrarPedidos())<span><kbd>F3</kbd> Pedidos</span>@endif
          <span><kbd>F4</kbd> Cliente</span>
          <span><kbd>F7</kbd> Comprobante</span>
          <span><kbd>F12</kbd> Cobrar</span>
        </span>
      </div>

      <div class="pos-grid">
        <div class="pos-main">
          {{-- Lector / búsqueda: el lector USB "teclea" el código y manda Enter --}}
          <div class="vx-scan">
            <svg class="vx-lupa" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M17 10.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z"/></svg>
            <input
              type="text"
              x-ref="buscadorProducto"
              autocomplete="off"
              placeholder="Escanea el código o escribe el producto…  (F2)"
              wire:model.live.debounce.250ms="busquedaProducto"
              x-on:input="hlProducto = -1"
              x-on:keydown.arrow-down.prevent="moverHl('producto', 1)"
              x-on:keydown.arrow-up.prevent="moverHl('producto', -1)"
              x-on:keydown.enter.prevent="if (! elegirHl('producto')) $wire.escanearOBuscar($event.target.value)"
            />

            @if ($busquedaProducto !== '')
              <ul class="vx-lista pos-resultados-producto" data-lista="producto">
                @forelse ($this->resultadosBusqueda() as $fila)
                  <li data-item wire:key="res-{{ $fila['producto_id'] }}-{{ $fila['presentacion_id'] ?? 'x' }}" :class="{ 'vx-hl': hlProducto === {{ $loop->index }} }">
                    @if ($fila['tipo'] === 'pesado')
                      <div x-data="{ peso: '1.000' }">
                        <span style="display:flex;align-items:center;gap:.5rem">
                          @if ($fila['imagen'])<img src="{{ Storage::disk('public')->url($fila['imagen']) }}" alt="" class="vx-thumb" />@endif
                          {{ $fila['etiqueta'] }} <span class="vx-muted">({{ $fila['precio_texto'] }})</span>
                        </span>
                        <span style="display:flex;align-items:center;gap:.4rem">
                          <input type="number" min="0.001" step="0.001" class="form-input" style="width:100px" x-model="peso" x-on:keydown.enter.stop="$wire.agregarProductoPorPeso({{ $fila['producto_id'] }}, peso)" />
                          <button type="button" data-elegir class="vx-opcion vx-sel" x-on:click="$wire.agregarProductoPorPeso({{ $fila['producto_id'] }}, peso)">Agregar</button>
                        </span>
                      </div>
                    @else
                      <button type="button"
                        wire:click="{{ $fila['presentacion_id'] ? 'agregarPresentacion('.$fila['presentacion_id'].')' : 'agregarProducto('.$fila['producto_id'].')' }}">
                        <span style="display:flex;align-items:center;gap:.5rem">
                          @if ($fila['imagen'])<img src="{{ Storage::disk('public')->url($fila['imagen']) }}" alt="" class="vx-thumb" />@endif
                          {{ $fila['etiqueta'] }}
                        </span>
                        <span style="display:flex;align-items:center;gap:.5rem">
                          @if ($fila['controla_stock'])
                            <span class="vx-badge {{ $fila['stock'] > 0 ? 'vx-badge-ok' : 'vx-badge-mal' }}">stock {{ number_format($fila['stock'], 2) }}</span>
                          @endif
                          <strong class="vx-num">{{ $fila['precio_texto'] }}</strong>
                        </span>
                      </button>
                    @endif
                  </li>
                @empty
                  <li class="vx-muted" style="padding:.8rem">Sin resultados.</li>
                @endforelse
              </ul>
            @endif
          </div>

          {{-- Pedidos a caja (enviados por el vendedor de piso a nombre del cliente) --}}
          @if ($this->puedeCobrarPedidos())
            <div class="vx-card">
              @if ($pedidoId)
                <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;flex-wrap:wrap">
                  <span><span class="vx-badge vx-badge-ok">Cobrando pedido</span> <strong>{{ $pedidoEtiqueta }}</strong></span>
                  <button type="button" class="vx-opcion" wire:click="soltarPedido">Soltar pedido</button>
                </div>
              @else
                @php $pedidos = $this->pedidosPendientes(); @endphp
                <div class="vx-titulo">
                  <span>Pedidos en espera @if ($pedidos->isNotEmpty())<span class="vx-badge vx-badge-ok">{{ $pedidos->count() }}</span>@endif</span>
                  <kbd>F3</kbd>
                </div>
                <input type="text" class="form-input" x-ref="buscadorPedido"
                  placeholder="¿A nombre de quién? Nombre o número del pedido…"
                  wire:model.live.debounce.300ms="busquedaPedido" />
                @if ($pedidos->isNotEmpty())
                  <ul wire:poll.15s style="margin-top:.4rem;display:grid;gap:.3rem">
                    @foreach ($pedidos as $pedido)
                      <li wire:key="pedido-{{ $pedido->id }}">
                        <button type="button" class="vx-opcion" style="width:100%;display:flex;justify-content:space-between;text-align:left" wire:click="cargarPedido({{ $pedido->id }})">
                          <span><strong>{{ $pedido->nombre_cliente }}</strong> <span class="vx-muted">{{ $pedido->etiquetaNumero() }}</span></span>
                          <span class="vx-muted" style="font-weight:500">
                            {{ $pedido->detalles_count }} art. · RD$ {{ number_format((float) $pedido->total_estimado, 2) }}
                            · {{ $pedido->vendedor?->name }} · {{ $pedido->created_at->diffForHumans() }}
                          </span>
                        </button>
                      </li>
                    @endforeach
                  </ul>
                @elseif ($busquedaPedido !== '')
                  <p class="vx-muted" style="margin-top:.4rem">No hay pedidos pendientes con ese nombre.</p>
                @endif
              @endif
            </div>
          @endif

          {{-- Carrito --}}
          <div class="vx-card vx-carrito">
            <table>
              <thead>
                <tr>
                  <th style="width:2.5rem">#</th>
                  <th>Descripción</th>
                  <th class="vx-num" style="text-align:center">Cantidad</th>
                  <th class="vx-num">Precio</th>
                  <th class="vx-num">Importe</th>
                  <th style="width:2.5rem"></th>
                </tr>
              </thead>
              <tbody>
                @forelse ($carrito as $indice => $linea)
                  @php $pesado = ($linea['tipo_venta'] ?? null) === 'pesado'; @endphp
                  <tr wire:key="linea-{{ $indice }}">
                    <td class="vx-muted">{{ $loop->iteration }}</td>
                    <td>
                      <div style="font-weight:600">
                        {{ $linea['nombre'] }}
                        @if (in_array($linea['producto_id'], $this->productosEnPromocion()))
                          <span class="vx-badge vx-badge-ok" title="Promoción aplicada automáticamente">Promo</span>
                        @endif
                      </div>
                      <div class="vx-codigo">{{ $linea['codigo'] }}</div>
                      @if ($this->lineaConStockInsuficiente($linea))
                        <span class="vx-badge vx-badge-mal">Stock insuficiente (disponible {{ number_format((float) $this->stockDeLinea($linea), 2) }})</span>
                      @endif
                    </td>
                    <td style="text-align:center">
                      <span class="vx-cant">
                        @unless ($pesado)
                          <button type="button" aria-label="Restar uno" wire:click="$set('carrito.{{ $indice }}.cantidad', {{ max(1, (int) $linea['cantidad'] - 1) }})">−</button>
                        @endunless
                        <input type="number" min="{{ $pesado ? '0.001' : '1' }}" step="{{ $pesado ? '0.001' : '1' }}"
                          wire:model.live.debounce.400ms="carrito.{{ $indice }}.cantidad" aria-label="Cantidad" />
                        @unless ($pesado)
                          <button type="button" aria-label="Sumar uno" wire:click="$set('carrito.{{ $indice }}.cantidad', {{ (int) $linea['cantidad'] + 1 }})">+</button>
                        @endunless
                      </span>
                      @if ($pesado)<span class="vx-muted"> {{ $linea['unidad_base'] }}</span>@endif
                    </td>
                    <td class="vx-num">{{ number_format((float) $linea['precio_unitario'], 2) }}</td>
                    <td class="vx-num" style="font-weight:700">{{ number_format((float) $this->subtotalLinea($linea), 2) }}</td>
                    <td>
                      <button type="button" class="vx-quitar" title="Quitar" aria-label="Quitar" wire:click="quitarLinea({{ $indice }})">
                        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                      </button>
                    </td>
                  </tr>
                @empty
                  <tr>
                    <td colspan="6" class="vx-vacio">
                      <div style="font-size:1.05rem;font-weight:600;color:#6B7280">Carrito vacío</div>
                      Escanea un código o busca el producto arriba para empezar.
                    </td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>

        {{-- Columna derecha: total, cliente, comprobante, descuento y cobro --}}
        <div class="pos-side">
          <div class="vx-total">
            <dl>
              <div><dt>Subtotal</dt><dd class="vx-num">{{ number_format((float) $totales['subtotal'], 2) }}</dd></div>
              @if ((float) $totales['descuento'] > 0)
                <div><dt>Descuento</dt><dd class="vx-num">-{{ number_format((float) $totales['descuento'], 2) }}</dd></div>
              @endif
              <div><dt>ITBIS 18%</dt><dd class="vx-num">{{ number_format((float) $totales['itbis_18'], 2) }}</dd></div>
              @if ((float) $totales['itbis_16'] > 0)
                <div><dt>ITBIS 16%</dt><dd class="vx-num">{{ number_format((float) $totales['itbis_16'], 2) }}</dd></div>
              @endif
            </dl>
            <div class="vx-gran">
              <span>TOTAL RD$</span>
              <span class="vx-num">{{ number_format((float) $totales['total'], 2) }}</span>
            </div>
            <div class="vx-articulos">{{ count($carrito) }} {{ count($carrito) === 1 ? 'línea' : 'líneas' }} · {{ rtrim(rtrim(number_format($articulos, 2), '0'), '.') }} artículos</div>

            @if ($this->hayLineasConStockInsuficiente())
              <p style="margin-top:.5rem;color:#FCA5A5;font-size:.85rem">Hay líneas con stock insuficiente; corrígelas para poder cobrar.</p>
            @endif

            <button type="button" class="btn-cobrar" x-ref="btnCobrar" x-on:click="abrirCobro()"
              @disabled(empty($carrito) || $this->hayLineasConStockInsuficiente())>
              Cobrar <kbd>F12</kbd>
            </button>
          </div>

          {{-- Cliente --}}
          <div class="vx-card">
            <div class="vx-titulo"><span>Cliente</span><kbd>F4</kbd></div>
            @if ($cliente = $this->clienteSeleccionado())
              <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem">
                <div>
                  <p style="font-weight:700">{{ $cliente->nombre }}</p>
                  @if ($cliente->documento)<p class="vx-muted">{{ $cliente->documento }}</p>@endif
                  @if ($listaAplicada = $this->listaPrecioAplicada())<p class="vx-muted">Lista de precio: {{ $listaAplicada }}</p>@endif
                </div>
                <button type="button" class="vx-opcion" wire:click="quitarCliente">Quitar</button>
              </div>
            @else
              <p style="font-weight:700;margin-bottom:.35rem">{{ \App\Models\Venta::ETIQUETA_AL_PORTADOR }}</p>
              <div style="position:relative">
                <input type="text" class="form-input" x-ref="buscadorCliente"
                  placeholder="Nombre, RNC o cédula…"
                  wire:model.live.debounce.300ms="busquedaCliente"
                  x-on:input="hlCliente = -1"
                  x-on:keydown.arrow-down.prevent="moverHl('cliente', 1)"
                  x-on:keydown.arrow-up.prevent="moverHl('cliente', -1)"
                  x-on:keydown.enter.prevent="elegirHl('cliente')" />
                @if ($busquedaCliente !== '')
                  <ul class="vx-lista pos-resultados-cliente" data-lista="cliente" style="top:46px">
                    @forelse ($this->clientesSugeridos() as $sugerido)
                      <li data-item wire:key="cli-{{ $sugerido->id }}" :class="{ 'vx-hl': hlCliente === {{ $loop->index }} }">
                        <button type="button" wire:click="seleccionarCliente({{ $sugerido->id }})">
                          <span>{{ $sugerido->nombre }}</span>
                          @if ($sugerido->documento)<span class="vx-muted">{{ $sugerido->documento }}</span>@endif
                        </button>
                      </li>
                    @empty
                      <li style="padding:.6rem;display:flex;justify-content:space-between;align-items:center;gap:.5rem">
                        <span class="vx-muted">Sin resultados locales.</span>
                        <button type="button" class="vx-opcion" wire:click="buscarClienteEnDgii">Buscar en DGII/JCE</button>
                      </li>
                    @endforelse
                  </ul>
                @endif
              </div>
            @endif
            @if ($this->mensajeFaltaRncComprador())
              <p class="vx-alerta" style="margin-top:.4rem">{{ $this->mensajeFaltaRncComprador() }}</p>
            @endif
          </div>

          {{-- Comprobante --}}
          <div class="vx-card">
            <div class="vx-titulo"><span>Comprobante</span><kbd>F7</kbd></div>
            <select class="form-select" x-ref="tipoComprobante" wire:model.live="tipoComprobante">
              @foreach ($this->tiposComprobante() as $valor => $etiqueta)
                <option value="{{ $valor }}">{{ $etiqueta }}</option>
              @endforeach
            </select>
            <p style="margin-top:.4rem;font-size:.85rem">
              Próximo NCF:
              @if ($this->proximoNcf())
                <strong class="vx-num">{{ $this->proximoNcf() }}</strong>
              @else
                <span class="vx-badge vx-badge-mal">No disponible: carga un rango de NCF</span>
              @endif
            </p>
          </div>

          {{-- Descuento (solo de la tabla maestra, con PIN de supervisor) --}}
          @if ($this->descuentosDisponibles()->isNotEmpty())
            <div class="vx-card">
              <div class="vx-titulo"><span>Descuento</span></div>
              <select class="form-select" wire:model.live="descuentoId">
                <option value="">Sin descuento</option>
                @foreach ($this->descuentosDisponibles() as $descuento)
                  <option value="{{ $descuento->id }}">{{ $descuento->nombre }} ({{ number_format((float) $descuento->porcentaje, 2) }}%)</option>
                @endforeach
              </select>
              @if (filled($descuentoId))
                @if ($this->descuentoAutorizado())
                  <p class="vx-muted" style="margin-top:.35rem">✔ Autorizado por <strong>{{ $descuentoAutorizadoNombre }}</strong></p>
                @else
                  <div style="display:flex;gap:.4rem;margin-top:.4rem" x-data="{ pin: '' }">
                    <input type="password" inputmode="numeric" maxlength="8" class="form-input" placeholder="PIN del supervisor" x-model="pin" autocomplete="off"
                      x-on:keydown.enter.stop.prevent="$wire.autorizarDescuento(pin); pin = ''" />
                    <button type="button" class="vx-opcion vx-sel" x-on:click="$wire.autorizarDescuento(pin); pin = ''">Autorizar</button>
                  </div>
                  <p class="vx-alerta" style="margin-top:.3rem;font-size:.8rem">El descuento no se aplica hasta que un supervisor lo autorice.</p>
                @endif
              @endif
            </div>
          @endif

          @if ($this->promocionesVigentes()->isNotEmpty())
            <div class="vx-card">
              <div class="vx-titulo"><span>Promociones activas</span></div>
              <ul style="font-size:.82rem;display:grid;gap:.2rem">
                @foreach ($this->promocionesVigentes() as $promo)
                  <li>
                    <strong>{{ $promo->nombre }}</strong>
                    <span class="vx-muted">({{ $promo->tipo->etiqueta() }}{{ $promo->valor ? ' — ' . ($promo->tipo->value === 'porcentaje' ? $promo->valor . '%' : 'RD$ ' . number_format((float) $promo->valor, 2)) : '' }}) · hasta {{ $promo->fecha_fin->format('d/m/Y') }}</span>
                  </li>
                @endforeach
              </ul>
            </div>
          @endif
        </div>
      </div>

      {{-- ============ MODAL DE COBRO ============ --}}
      <div class="vx-modal-fondo" x-show="modalCobro" x-cloak x-transition.opacity x-on:click.self="modalCobro = false">
        <div class="vx-modal" x-on:click.stop>
          <div class="vx-modal-cab">
            <div>
              <div style="font-size:.75rem;letter-spacing:.08em;color:#94A3B8">TOTAL A COBRAR</div>
              <div class="vx-num">RD$ {{ number_format((float) $totales['total'], 2) }}</div>
            </div>
            <button type="button" style="color:#fff;font-size:1.8rem;line-height:1" aria-label="Cerrar" x-on:click="modalCobro = false">&times;</button>
          </div>

          <div class="vx-modal-cuerpo">
            <div>
              <div class="vx-titulo"><span>Tipo de pago</span></div>
              <div class="vx-opciones">
                <button type="button" class="vx-opcion {{ ! $ventaACredito ? 'vx-sel' : '' }}" wire:click="$set('ventaACredito', false)">Contado</button>
                <button type="button" class="vx-opcion {{ $ventaACredito ? 'vx-sel' : '' }}" wire:click="$set('ventaACredito', true)">A crédito (fiado)</button>
              </div>
            </div>

            @if ($ventaACredito)
              <div>
                <label class="form-label">Fecha límite de pago</label>
                <input type="date" class="form-input" wire:model.live="fechaLimitePago" min="{{ now()->toDateString() }}" />
                <p class="vx-muted" style="font-size:.8rem;margin-top:.25rem">Si la dejas vacía, se usan 30 días desde hoy.</p>
              </div>
            @else
              <div>
                <div class="vx-titulo"><span>Forma de pago</span></div>
                <div class="vx-opciones">
                  @foreach ($this->formasPago() as $valor => $etiqueta)
                    <button type="button" wire:key="fp-{{ $valor }}" class="vx-opcion {{ $formaPago === $valor ? 'vx-sel' : '' }}"
                      wire:click="$set('formaPago', '{{ $valor }}')">{{ $etiqueta }}</button>
                  @endforeach
                </div>
              </div>

              @if ($formaPago === \App\Enums\FormaPago::EFECTIVO->value)
                <div class="vx-efectivo" x-data="{ total: {{ (float) ($totales['total'] ?? 0) }} }">
                  <label class="form-label">Monto recibido (efectivo)</label>
                  <input type="number" step="0.01" min="0" max="99999999.99" x-ref="efectivo"
                    wire:model.live.debounce.300ms="montoEfectivo" placeholder="{{ number_format((float) ($totales['total'] ?? 0), 2, '.', '') }}" />
                  <div class="vx-billetes">
                    @foreach ([100, 200, 500, 1000, 2000] as $billete)
                      <button type="button" class="vx-opcion" x-on:click="$refs.efectivo.value = String((parseFloat($refs.efectivo.value || '0') + {{ $billete }}).toFixed(2)); $wire.set('montoEfectivo', $refs.efectivo.value)">+{{ number_format($billete) }}</button>
                    @endforeach
                    <button type="button" class="vx-opcion" x-on:click="$refs.efectivo.value = total.toFixed(2); $wire.set('montoEfectivo', $refs.efectivo.value)">Exacto</button>
                    <button type="button" class="vx-opcion" style="grid-column: span 2" x-on:click="$refs.efectivo.value = ''; $wire.set('montoEfectivo', '')">Limpiar</button>
                  </div>
                </div>
              @endif

              @if ($formaPago === \App\Enums\FormaPago::MIXTO->value)
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                  <div>
                    <label class="form-label">Efectivo</label>
                    <input type="number" step="0.01" min="0" max="99999999.99" class="form-input" x-ref="efectivo" wire:model.live.debounce.400ms="montoEfectivo" />
                  </div>
                  <div>
                    <label class="form-label">Tarjeta</label>
                    <input type="number" step="0.01" min="0" max="99999999.99" class="form-input" wire:model.live.debounce.400ms="montoTarjeta" />
                  </div>
                </div>
              @endif

              @if (bccomp($this->vueltoCalculado(), '0.00', 2) > 0)
                <div class="vx-vuelto pos-modal-vuelto">
                  <span>Vuelto</span>
                  <span class="vx-num">RD$ {{ number_format((float) $this->vueltoCalculado(), 2) }}</span>
                </div>
              @endif
            @endif

            {{-- Saldo a favor del cliente --}}
            @if ($clienteId && auth()->user()->can('clientes.saldo_favor'))
              @php $saldoDisponible = $this->saldoFavorCliente(); @endphp
              @if (bccomp($saldoDisponible, '0.00', 2) > 0)
                <div style="background:var(--primary-50);border:1px solid var(--primary-200);border-radius:10px;padding:.7rem">
                  <p style="font-weight:700;color:var(--primary-800)">Saldo a favor: RD$ {{ number_format((float) $saldoDisponible, 2) }}</p>
                  <label class="form-label" style="font-size:.8rem;margin-top:.3rem">Usar saldo (monto)</label>
                  <input type="number" step="0.01" min="0" max="{{ $saldoDisponible }}" class="form-input" wire:model.live.debounce.400ms="usarSaldoFavor" placeholder="0.00" />
                </div>
              @endif
              @if (! $ventaACredito && in_array($formaPago, [\App\Enums\FormaPago::EFECTIVO->value, \App\Enums\FormaPago::MIXTO->value]) && bccomp($this->vueltoCalculado(), '0.00', 2) > 0)
                <label style="display:flex;align-items:center;gap:.5rem;font-size:.85rem">
                  <input type="checkbox" wire:model.live="dejarVueltoEnFondo" />
                  Dejar vuelto (RD$ {{ number_format((float) $this->vueltoCalculado(), 2) }}) como saldo a favor
                </label>
              @endif
            @endif
          </div>

          <div class="vx-modal-pie">
            <button type="button" class="btn-secundario" x-on:click="modalCobro = false">Volver <kbd>Esc</kbd></button>
            <button type="button" class="btn-cobrar" x-ref="btnConfirmar" wire:click="cobrar" wire:loading.attr="disabled" wire:target="cobrar" @disabled(! $this->puedeCobrar())>
              <span wire:loading.remove wire:target="cobrar">{{ $ventaACredito ? 'Confirmar fiado' : 'Confirmar cobro' }} <kbd>Enter</kbd></span>
              <span wire:loading wire:target="cobrar">Procesando…</span>
            </button>
          </div>
        </div>
      </div>
    @endif
  </div>
</x-filament-panels::page>
