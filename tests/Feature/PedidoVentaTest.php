<?php

namespace Tests\Feature;

use App\Enums\EstadoPedidoVenta;
use App\Enums\Modulo;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoProducto;
use App\Filament\Pages\PuntoDeVenta;
use App\Filament\Pages\TomarPedido;
use App\Models\Empresa;
use App\Models\PedidoVenta;
use App\Models\Producto;
use App\Models\SecuenciaNcf;
use App\Models\User;
use App\Models\Venta;
use App\Services\ArqueoCajaService;
use App\Services\ModulosEmpresaService;
use App\Services\PedidoVentaService;
use App\Services\RolesEmpresaService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Pedidos a caja: el vendedor de piso arma el pedido a nombre del cliente, la caja lo busca por
 * nombre y lo cobra. No se cobra dos veces, no cruza empresas y el precio lo decide la base.
 */
class PedidoVentaTest extends TestCase
{
    use RefreshDatabase;

    private Producto $producto;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        SecuenciaNcf::create([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO_FISICA->value,
            'prefijo' => 'B02',
            'secuencia_desde' => 1,
            'secuencia_actual' => 1,
            'secuencia_hasta' => 1000,
            'vencimiento' => now()->addYear(),
            'activa' => true,
        ]);

        $this->producto = Producto::create([
            'codigo' => 'CEM-1', 'nombre' => 'Cemento gris', 'tipo' => TipoProducto::PRODUCTO,
            'costo' => 400, 'precio' => 500, 'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true, 'stock' => 100, 'stock_minimo' => 0, 'activo' => true,
        ]);

        $this->admin = User::factory()->create();
        app(RolesEmpresaService::class)->asignarAdministrador($this->admin, $this->empresaDefault);
    }

    private function crearPedido(string $nombre = 'Juan Pérez', float $cantidad = 2, ?Empresa $empresa = null, ?int $productoId = null): PedidoVenta
    {
        return app(PedidoVentaService::class)->crear(
            $empresa ?? $this->empresaDefault,
            $this->admin,
            $nombre,
            [['producto_id' => $productoId ?? $this->producto->id, 'cantidad' => $cantidad]],
        );
    }

    public function test_el_vendedor_envia_el_pedido_a_caja_desde_tomar_pedido(): void
    {
        Livewire::actingAs($this->admin)
            ->test(TomarPedido::class)
            ->call('agregarProducto', $this->producto->id)
            ->set('carrito.0.cantidad', 3)
            ->set('nombreCliente', '  Juan   Pérez ')
            ->call('enviarACaja')
            ->assertSet('carrito', [])
            ->assertSet('nombreCliente', '');

        $pedido = PedidoVenta::sole();
        $this->assertSame('Juan Pérez', $pedido->nombre_cliente);
        $this->assertSame(EstadoPedidoVenta::PENDIENTE, $pedido->estado);
        $this->assertSame(1, $pedido->numero);
        $this->assertEquals(3, (float) $pedido->detalles->sole()->cantidad);
        $this->assertEquals(1500, (float) $pedido->total_estimado);
        $this->assertSame($this->empresaDefault->id, $pedido->empresa_id);
    }

    public function test_sin_nombre_no_se_envia(): void
    {
        Livewire::actingAs($this->admin)
            ->test(TomarPedido::class)
            ->call('agregarProducto', $this->producto->id)
            ->set('nombreCliente', '   ')
            ->call('enviarACaja');

        $this->assertSame(0, PedidoVenta::count());
    }

    public function test_tomar_pedido_no_cobra(): void
    {
        Livewire::actingAs($this->admin)
            ->test(TomarPedido::class)
            ->call('agregarProducto', $this->producto->id)
            ->call('cobrar');

        $this->assertSame(0, Venta::count());
    }

    public function test_la_numeracion_es_correlativa_por_empresa(): void
    {
        $otra = Empresa::factory()->create();
        $productoOtra = Producto::withoutGlobalScopes()->create([
            'empresa_id' => $otra->id, 'codigo' => 'X', 'nombre' => 'Arena', 'tipo' => TipoProducto::PRODUCTO,
            'costo' => 1, 'precio' => 2, 'tasa_itbis' => TasaItbis::DIECIOCHO, 'controla_stock' => false, 'activo' => true,
        ]);

        $this->assertSame(1, $this->crearPedido()->numero);
        $this->assertSame(2, $this->crearPedido()->numero);
        $this->assertSame(1, $this->crearPedido(empresa: $otra, productoId: $productoOtra->id)->numero);
    }

    public function test_rechaza_productos_de_otra_empresa(): void
    {
        $otra = Empresa::factory()->create();
        $ajeno = Producto::withoutGlobalScopes()->create([
            'empresa_id' => $otra->id, 'codigo' => 'AJ', 'nombre' => 'Ajeno', 'tipo' => TipoProducto::PRODUCTO,
            'costo' => 1, 'precio' => 2, 'tasa_itbis' => TasaItbis::DIECIOCHO, 'controla_stock' => false, 'activo' => true,
        ]);

        $this->expectException(RuntimeException::class);

        $this->crearPedido(productoId: $ajeno->id);
    }

    public function test_la_caja_busca_por_nombre_o_numero(): void
    {
        $juan = $this->crearPedido('Juan Pérez');
        $this->crearPedido('María Rodríguez');

        $servicio = app(PedidoVentaService::class);

        $this->assertSame([$juan->id], $servicio->pendientes($this->empresaDefault, 'juan')->pluck('id')->all());
        $this->assertSame([$juan->id], $servicio->pendientes($this->empresaDefault, 'P-0001')->pluck('id')->all());
        $this->assertCount(2, $servicio->pendientes($this->empresaDefault));
        // Un comodín de LIKE escrito por el cajero no lista todo.
        $this->assertCount(0, $servicio->pendientes($this->empresaDefault, '%'));
    }

    public function test_la_caja_carga_el_pedido_y_lo_cobra(): void
    {
        $pedido = $this->crearPedido(cantidad: 2);
        app(ArqueoCajaService::class)->abrir('0', $this->admin->id, $this->empresaDefault);

        $pos = Livewire::actingAs($this->admin)
            ->test(PuntoDeVenta::class)
            ->set('tipoComprobante', TipoComprobante::FACTURA_CONSUMO_FISICA->value)
            ->call('cargarPedido', $pedido->id)
            ->assertSet('pedidoId', $pedido->id)
            ->assertSet('carrito.0.producto_id', $this->producto->id);

        // Precio manipulado en el navegador: se cobra el de la base.
        $pos->set('carrito.0.precio_unitario', '1')
            ->set('formaPago', 'efectivo')
            ->set('montoEfectivo', '2000')
            ->call('cobrar')
            ->assertSet('pedidoId', null);

        $pedido->refresh();
        $this->assertSame(EstadoPedidoVenta::COBRADO, $pedido->estado);
        $this->assertNotNull($pedido->venta_id);
        $this->assertSame($this->admin->id, $pedido->cobrado_por);
        $this->assertEquals(1000, (float) $pedido->venta->subtotal);
        $this->assertEquals(98, (float) $this->producto->fresh()->stock);
    }

    public function test_un_pedido_ya_cobrado_no_llega_a_registrar_otra_venta(): void
    {
        $pedido = $this->crearPedido();
        // Simula que otra caja lo cobró un instante antes.
        $pedido->update(['estado' => EstadoPedidoVenta::COBRADO, 'cobrado_en' => now()]);

        $llamadas = 0;

        try {
            app(PedidoVentaService::class)->cobrar($this->empresaDefault, $pedido->id, $this->admin->id, function () use (&$llamadas) {
                $llamadas++;

                return new Venta;
            });
            $this->fail('Debió rechazar el cobro de un pedido ya cobrado.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ya fue', $e->getMessage());
        }

        $this->assertSame(0, $llamadas);
    }

    public function test_si_la_venta_falla_el_pedido_sigue_pendiente(): void
    {
        $pedido = $this->crearPedido();

        try {
            app(PedidoVentaService::class)->cobrar($this->empresaDefault, $pedido->id, $this->admin->id, fn () => throw new RuntimeException('Sin NCF'));
        } catch (RuntimeException) {
        }

        $this->assertSame(EstadoPedidoVenta::PENDIENTE, $pedido->fresh()->estado);
    }

    public function test_no_se_cobra_un_pedido_de_otra_empresa(): void
    {
        $otra = Empresa::factory()->create();
        $productoOtra = Producto::withoutGlobalScopes()->create([
            'empresa_id' => $otra->id, 'codigo' => 'X', 'nombre' => 'Arena', 'tipo' => TipoProducto::PRODUCTO,
            'costo' => 1, 'precio' => 2, 'tasa_itbis' => TasaItbis::DIECIOCHO, 'controla_stock' => false, 'activo' => true,
        ]);
        $ajeno = $this->crearPedido(empresa: $otra, productoId: $productoOtra->id);

        $this->assertNull(app(PedidoVentaService::class)->pendienteParaCargar($this->empresaDefault, $ajeno->id));

        Livewire::actingAs($this->admin)
            ->test(PuntoDeVenta::class)
            ->call('cargarPedido', $ajeno->id)
            ->assertSet('pedidoId', null)
            ->assertSet('carrito', []);

        $this->expectException(RuntimeException::class);
        app(PedidoVentaService::class)->cobrar($this->empresaDefault, $ajeno->id, $this->admin->id, fn () => new Venta);
    }

    public function test_cancelar_exige_motivo_y_solo_pendientes(): void
    {
        $pedido = $this->crearPedido();
        $servicio = app(PedidoVentaService::class);

        $servicio->cancelar($pedido, $this->empresaDefault, $this->admin->id, 'El cliente se fue');
        $this->assertSame(EstadoPedidoVenta::CANCELADO, $pedido->fresh()->estado);
        $this->assertCount(0, $servicio->pendientes($this->empresaDefault));

        $this->expectException(RuntimeException::class);
        $servicio->cancelar($pedido->fresh(), $this->empresaDefault, $this->admin->id, 'otra vez');
    }

    public function test_con_el_modulo_apagado_la_caja_no_ve_pedidos(): void
    {
        $this->crearPedido();
        app(ModulosEmpresaService::class)->actualizarModulos($this->empresaDefault, [Modulo::VENTAS_PEDIDOS->value => false]);

        $pos = Livewire::actingAs($this->admin)->test(PuntoDeVenta::class);

        $this->assertCount(0, $pos->instance()->pedidosPendientes());
        $this->assertFalse(TomarPedido::canAccess());
    }

    public function test_el_listado_de_pedidos_solo_muestra_los_de_la_empresa(): void
    {
        $propio = $this->crearPedido();
        $otra = Empresa::factory()->create();
        $productoOtra = Producto::withoutGlobalScopes()->create([
            'empresa_id' => $otra->id, 'codigo' => 'X', 'nombre' => 'Arena', 'tipo' => TipoProducto::PRODUCTO,
            'costo' => 1, 'precio' => 2, 'tasa_itbis' => TasaItbis::DIECIOCHO, 'controla_stock' => false, 'activo' => true,
        ]);
        $ajeno = $this->crearPedido(empresa: $otra, productoId: $productoOtra->id);

        Livewire::actingAs($this->admin)
            ->test(\App\Filament\Resources\PedidoVentaResource\Pages\ListPedidosVenta::class)
            ->assertCanSeeTableRecords([$propio])
            ->assertCanNotSeeTableRecords([$ajeno]);
    }

    public function test_el_pos_tactil_carga_y_cobra_el_pedido(): void
    {
        $pedido = $this->crearPedido('Pedro', 3);
        $caja = \App\Models\Caja::create(['empresa_id' => $this->empresaDefault->id, 'nombre' => 'Caja 1', 'activo' => true]);
        app(ArqueoCajaService::class)->abrir('0', $this->admin->id, $this->empresaDefault, $caja);

        Livewire::actingAs($this->admin)
            ->test(\App\Filament\Pages\PuntoDeVentaTouch::class)
            ->call('seleccionarCaja', $caja->id)
            ->assertSee('PEDIDOS EN ESPERA')
            ->call('cargarPedido', $pedido->id)
            ->assertSet('pedidoId', $pedido->id)
            ->assertSet('carrito.0.cantidad', '3')
            ->set('tipoComprobante', TipoComprobante::FACTURA_CONSUMO_FISICA->value)
            ->set('formaPago', 'efectivo')
            ->set('montoEfectivo', '5000')
            ->call('cobrar')
            ->assertSet('pedidoId', null);

        $this->assertSame(EstadoPedidoVenta::COBRADO, $pedido->fresh()->estado);
        $this->assertSame($caja->id, $pedido->fresh()->venta->caja_id);
    }

    private function cotizacionAprobada(string $precio = '450', string $descuento = '100', int $dias = 15): \App\Models\Cotizacion
    {
        $cotizacion = app(\App\Services\CotizacionService::class)->crear([
            'cliente_id' => null, 'fecha' => now()->toDateString(), 'dias_vigencia' => $dias,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 2, 'precio_unitario' => $precio, 'descuento' => $descuento]],
        ], $this->admin->id, $this->empresaDefault);
        $cotizacion->update(['estado' => \App\Enums\EstadoCotizacion::APROBADA]);

        return $cotizacion;
    }

    /** Cotización aprobada → caja: se cobra al precio cotizado y la cotización queda facturada. */
    public function test_la_cotizacion_enviada_a_caja_se_cobra_al_precio_cotizado(): void
    {
        $cotizacion = $this->cotizacionAprobada();   // 2 × 450 − 100 = 800 (vs. 1,000 a precio normal)
        $pedido = app(\App\Services\CotizacionService::class)->enviarACaja($cotizacion, $this->empresaDefault, $this->admin);
        $this->assertSame($cotizacion->id, $pedido->cotizacion_id);
        $this->assertEquals(400, (float) $pedido->detalles->first()->precio_estimado);

        app(ArqueoCajaService::class)->abrir('0', $this->admin->id, $this->empresaDefault);

        Livewire::actingAs($this->admin)
            ->test(\App\Filament\Pages\Caja::class)
            ->assertSee('Pedidos en espera')
            ->set('tipoComprobante', TipoComprobante::FACTURA_CONSUMO_FISICA->value)
            ->call('cargarPedido', $pedido->id)
            ->set('carrito.0.precio_unitario', '1')     // manipulado: vale el cotizado, no este
            ->set('formaPago', 'efectivo')
            ->set('montoEfectivo', '2000')
            ->call('cobrar')
            ->assertSet('pedidoId', null);

        $cotizacion->refresh();
        $this->assertSame(\App\Enums\EstadoCotizacion::FACTURADA, $cotizacion->estado);
        $this->assertNotNull($cotizacion->venta_id);
        $this->assertEquals(800, (float) $cotizacion->venta->subtotal);
        $this->assertNotNull($cotizacion->venta->arqueo_caja_id);  // quedó en el turno de la caja
    }

    public function test_no_se_envia_dos_veces_ni_vencida(): void
    {
        $cotizacion = $this->cotizacionAprobada();
        $servicio = app(\App\Services\CotizacionService::class);
        $servicio->enviarACaja($cotizacion, $this->empresaDefault, $this->admin);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ya está en caja');
        $servicio->enviarACaja($cotizacion->fresh(), $this->empresaDefault, $this->admin);
    }

    /** Si vence mientras espera en caja, se cobra al precio actual. */
    public function test_cotizacion_vencida_en_caja_se_cobra_al_precio_actual(): void
    {
        $cotizacion = $this->cotizacionAprobada();
        $pedido = app(\App\Services\CotizacionService::class)->enviarACaja($cotizacion, $this->empresaDefault, $this->admin);
        $cotizacion->update(['fecha_vencimiento' => now()->subDay()]);
        app(ArqueoCajaService::class)->abrir('0', $this->admin->id, $this->empresaDefault);

        Livewire::actingAs($this->admin)
            ->test(PuntoDeVenta::class)
            ->set('tipoComprobante', TipoComprobante::FACTURA_CONSUMO_FISICA->value)
            ->call('cargarPedido', $pedido->id)
            ->set('formaPago', 'efectivo')
            ->set('montoEfectivo', '2000')
            ->call('cobrar');

        $this->assertEquals(1000, (float) $pedido->fresh()->venta->subtotal);
    }

    /** pedidoId está bloqueado: el navegador no puede apuntar a otro pedido para tomar sus precios. */
    public function test_el_navegador_no_puede_cambiar_el_pedido_cargado(): void
    {
        app(ArqueoCajaService::class)->abrir('0', $this->admin->id, $this->empresaDefault);

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->admin)->test(PuntoDeVenta::class)->set('pedidoId', 999);
    }

    /** Quien puede cobrar en la pantalla ve los pedidos, aunque su rol no tenga pedidos.cobrar. */
    public function test_un_cajero_con_acceso_al_pos_ve_los_pedidos_sin_permiso_aparte(): void
    {
        $pedido = $this->crearPedido('Ana Ruiz');
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'pos.acceder', 'guard_name' => 'web']);
        $rol = \App\Models\Role::firstOrCreate(['empresa_id' => $this->empresaDefault->id, 'name' => 'Solo caja', 'guard_name' => 'web']);
        $rol->syncPermissions(['pos.acceder']);
        $cajero = User::factory()->create();
        $cajero->assignRole('Solo caja');
        app(ArqueoCajaService::class)->abrir('0', $cajero->id, $this->empresaDefault);

        Livewire::actingAs($cajero)
            ->test(\App\Filament\Pages\Caja::class)
            ->assertSee('Ana Ruiz')
            ->call('cargarPedido', $pedido->id)
            ->assertSet('pedidoId', $pedido->id);
    }
}
