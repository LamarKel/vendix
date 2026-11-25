<?php

namespace Tests\Feature;

use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoMovimiento;
use App\Enums\TipoProducto;
use App\Enums\TipoProveedor;
use App\Enums\TipoVenta;
use App\Filament\Resources\CompraResource;
use App\Filament\Resources\CompraResource\Pages\CreateCompra;
use App\Models\Compra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\CompraService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Compra de productos que se venden por peso: se digita lo que pesó la mercancía y lo pagado; el
 * peso entra al inventario y el costo por libra sale de dividirlos, sin descuadrar el total.
 */
class CompraPorPesoTest extends TestCase
{
    use RefreshDatabase;

    private Producto $papa;

    private Proveedor $proveedor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->papa = Producto::create([
            'empresa_id' => $this->empresaDefault->id,
            'codigo' => 'VIV-001',
            'nombre' => 'Papa',
            'tipo' => TipoProducto::PRODUCTO,
            'tipo_venta' => TipoVenta::PESADO,
            'unidad_base' => 'libra',
            'costo' => 20,
            'precio' => 32,
            'precio_por_peso' => 32,
            'tasa_itbis' => TasaItbis::EXENTO,
            'controla_stock' => true,
            'stock' => 10,
            'stock_minimo' => 0,
            'activo' => true,
        ]);

        $this->proveedor = Proveedor::create([
            'empresa_id' => $this->empresaDefault->id,
            'nombre' => 'Mercado Nuevo',
            'rnc' => '131222333',
            'tipo' => TipoProveedor::FORMAL,
            'activo' => true,
        ]);
    }

    private function comprar(array $linea, bool $itbisIncluido = false): Compra
    {
        return app(CompraService::class)->crear([
            'proveedor_id' => $this->proveedor->id,
            'tipo_comprobante' => TipoComprobante::COMPRAS,
            'ncf' => null,
            'fecha' => now(),
            'itbis_incluido' => $itbisIncluido,
            'lineas' => [['producto_id' => $this->papa->id] + $linea],
        ], User::factory()->create()->id, $this->empresaDefault);
    }

    public function test_calculo_de_costo_por_peso(): void
    {
        $this->assertSame(
            ['total' => '2400.00', 'cantidad' => '196.000', 'costo_unitario' => '12.24'],
            CompraResource::calcularCompraPorPeso('196', '2400'),
        );
        // 2,000 ÷ 7.5 = 266.666… → 266.67 (redondeo hacia arriba desde ,5).
        $this->assertSame('266.67', CompraResource::calcularCompraPorPeso(7.5, 2000)['costo_unitario']);

        $this->assertNull(CompraResource::calcularCompraPorPeso(0, 100));
        $this->assertNull(CompraResource::calcularCompraPorPeso(-5, 100));
        $this->assertNull(CompraResource::calcularCompraPorPeso(10, -1));
        $this->assertNull(CompraResource::calcularCompraPorPeso('', 100));
        $this->assertNull(CompraResource::calcularCompraPorPeso(10, null));
    }

    public function test_el_peso_entra_al_inventario_y_el_total_cuadra_con_lo_pagado(): void
    {
        // 196 lb por RD$2,400: el costo por libra se redondea (12.24) pero el subtotal no.
        $compra = $this->comprar(['cantidad' => '196.000', 'costo_unitario' => '12.24', 'total' => '2400.00']);

        $this->assertSame('2400.00', (string) $compra->subtotal);
        $this->assertSame('2400.00', (string) $compra->total);

        $detalle = $compra->detalles()->sole();
        $this->assertSame('196.000', (string) $detalle->cantidad);
        $this->assertSame('12.24', (string) $detalle->costo_unitario);
        $this->assertSame('2400.00', (string) $detalle->subtotal);

        $this->papa->refresh();
        $this->assertSame('206.000', (string) $this->papa->stock); // 10 + 196 lb
        $this->assertSame('12.24', (string) $this->papa->costo);   // última compra (default)

        $this->assertDatabaseHas('movimientos_inventario', [
            'producto_id' => $this->papa->id,
            'tipo' => TipoMovimiento::ENTRADA->value,
            'cantidad' => '196.000',
        ]);
    }

    public function test_con_itbis_incluido_el_total_se_separa_sin_perder_centavos(): void
    {
        $this->papa->update(['tasa_itbis' => TasaItbis::DIECIOCHO]);

        $compra = $this->comprar(['cantidad' => '50', 'costo_unitario' => '23.60', 'total' => '1180.00'], itbisIncluido: true);

        $this->assertSame('1000.00', (string) $compra->subtotal);
        $this->assertSame('180.00', (string) $compra->itbis);
        $this->assertSame('1180.00', (string) $compra->total);
        $this->assertSame('20.00', (string) $compra->detalles()->sole()->costo_unitario);
    }

    public function test_sin_total_la_linea_se_calcula_como_siempre(): void
    {
        $compra = $this->comprar(['cantidad' => '5', 'costo_unitario' => '60']);

        $this->assertSame('300.00', (string) $compra->subtotal);
        $this->assertSame('60.00', (string) $compra->detalles()->sole()->costo_unitario);
    }

    public function test_compra_y_devolucion_de_un_producto_exento_no_fallan(): void
    {
        // La papa es exenta: antes calcularTotales() reventaba con UnhandledMatchError.
        $compra = $this->comprar(['cantidad' => '100', 'costo_unitario' => '12', 'total' => '1200.00']);

        $this->assertSame('1200.00', (string) $compra->monto_exento);
        $this->assertSame('0.00', (string) $compra->itbis);

        $devolucion = app(\App\Services\DevolucionCompraService::class)->crear([
            'compra_id' => $compra->id,
            'fecha' => now(),
            'motivo' => 'Vino mojada',
            'lineas' => [['detalle_compra_id' => $compra->detalles()->sole()->id, 'cantidad' => 10]],
        ], User::factory()->create()->id, $this->empresaDefault);

        $this->assertSame('120.00', (string) $devolucion->subtotal);
        $this->assertSame('0.00', (string) $devolucion->itbis);
        $this->assertSame('100.000', (string) $this->papa->refresh()->stock); // 10 + 100 − 10
    }

    public function test_rechaza_total_negativo(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no puede ser negativo');

        $this->comprar(['cantidad' => '10', 'costo_unitario' => '1', 'total' => '-50']);
    }

    // ── Pantalla ───────────────────────────────────────────────────────────

    private function almacenista(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $usuario = User::factory()->create(['empresa_id' => $this->empresaDefault->id]);
        $usuario->assignRole('Almacenista');

        return $usuario;
    }

    public function test_un_producto_por_peso_pide_peso_y_total_pagado(): void
    {
        $otro = Producto::create([
            'empresa_id' => $this->empresaDefault->id,
            'codigo' => 'ABA-001',
            'nombre' => 'Aceite',
            'tipo' => TipoProducto::PRODUCTO,
            'costo' => 75,
            'precio' => 95,
            'tasa_itbis' => TasaItbis::DIECISEIS,
            'controla_stock' => true,
            'stock' => 0,
            'activo' => true,
        ]);

        Livewire::actingAs($this->almacenista())
            ->test(CreateCompra::class)
            ->set('data.nueva_linea_producto_id', $this->papa->id)
            ->assertSet('data.nueva_linea_por_peso', true)
            ->assertSee('Peso (lb)')
            ->assertSee('Total pagado')
            ->set('data.nueva_linea_producto_id', $otro->id)
            ->assertSet('data.nueva_linea_por_peso', false);
    }

    public function test_agregar_linea_por_peso_y_registrar_la_compra(): void
    {
        Livewire::actingAs($this->almacenista())
            ->test(CreateCompra::class)
            ->set('data.proveedor_id', $this->proveedor->id)
            ->set('data.nueva_linea_producto_id', $this->papa->id)
            ->set('data.nueva_linea_peso', '196')
            ->set('data.nueva_linea_total_pagado', '2400')
            ->assertSee('Costo por lb: RD$12.24')
            ->call('agregarLinea')
            ->assertSet('data.nueva_linea_producto_id', null)
            ->assertSet('data.nueva_linea_por_peso', false)
            ->assertSee('196.00 lb')
            ->set('data.monto_total_factura', '2400')
            ->call('create')
            ->assertHasNoFormErrors();

        $compra = Compra::sole();
        $this->assertSame('2400.00', (string) $compra->total);
        $this->assertSame('196.000', (string) $compra->detalles()->sole()->cantidad);
        $this->assertSame('206.000', (string) $this->papa->refresh()->stock);
    }

    public function test_sin_peso_o_total_no_agrega_la_linea(): void
    {
        $componente = Livewire::actingAs($this->almacenista())
            ->test(CreateCompra::class)
            ->set('data.nueva_linea_producto_id', $this->papa->id)
            ->set('data.nueva_linea_peso', '196')
            ->call('agregarLinea')
            ->assertNotified('Selecciona un producto e indica el peso y el total pagado.');

        // El repeater arranca con una fila vacía: no debe haber ninguna con producto.
        $this->assertEmpty(collect($componente->get('data.lineas'))->filter(fn ($l) => filled($l['producto_id'] ?? null)));
    }
}
