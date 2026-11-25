<?php

namespace Tests\Feature;

use App\Enums\OrigenMovimiento;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoLocalidad;
use App\Enums\TipoMovimiento;
use App\Enums\TipoProducto;
use App\Enums\TipoProveedor;
use App\Exceptions\StockInsuficienteException;
use App\Models\Empresa;
use App\Models\Localidad;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\SecuenciaNcf;
use App\Models\StockLocalidad;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\CompraService;
use App\Services\InventarioService;
use App\Services\LocalidadService;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Sucursales → localidades → stock por localidad. Las ventas descuentan de la localidad de venta
 * de su sucursal; las compras entran a recepción (si la empresa lo usa); el total del producto es
 * siempre la suma de sus localidades.
 */
class SucursalesLocalidadesTest extends TestCase
{
    use RefreshDatabase;

    private Producto $producto;

    private LocalidadService $loc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loc = app(LocalidadService::class);

        SecuenciaNcf::create([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO_FISICA->value,
            'prefijo' => 'B02', 'secuencia_desde' => 1, 'secuencia_actual' => 1, 'secuencia_hasta' => 1000,
            'vencimiento' => now()->addYear(), 'activa' => true,
        ]);

        $this->producto = Producto::create([
            'codigo' => 'CEM', 'nombre' => 'Cemento', 'tipo' => TipoProducto::PRODUCTO,
            'costo' => 400, 'precio' => 500, 'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true, 'stock' => 10, 'stock_minimo' => 0, 'activo' => true,
        ]);
    }

    private function principal(): Sucursal
    {
        return $this->loc->principal($this->empresaDefault);
    }

    private function localidad(Sucursal $s, TipoLocalidad $tipo): Localidad
    {
        return Localidad::where('sucursal_id', $s->id)->where('tipo', $tipo->value)->firstOrFail();
    }

    private function stock(Localidad $l): float
    {
        return (float) app(InventarioService::class)->stockEn($l, $this->producto);
    }

    private function segundaSucursal(): Sucursal
    {
        $s = Sucursal::create(['empresa_id' => $this->empresaDefault->id, 'nombre' => 'Norte']);
        $this->loc->asegurarLocalidadesBase($s);

        return $s;
    }

    private function entrada(Localidad $l, float $cantidad): void
    {
        DB::transaction(fn () => app(InventarioService::class)->registrarMovimiento(
            $this->producto, TipoMovimiento::ENTRADA, OrigenMovimiento::AJUSTE, (string) $cantidad, localidad: $l,
        ));
    }

    private function vender(float $cantidad, ?User $usuario = null): void
    {
        app(VentaService::class)->registrar([
            'user_id' => $usuario?->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO_FISICA->value,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => $cantidad]],
        ], $this->empresaDefault);
    }

    private function totalLocalidades(): float
    {
        return (float) StockLocalidad::where('producto_id', $this->producto->id)->sum('cantidad');
    }

    public function test_toda_empresa_nace_con_su_sucursal_principal_y_tres_localidades(): void
    {
        $empresa = Empresa::factory()->create();
        $sucursal = Sucursal::where('empresa_id', $empresa->id)->sole();

        $this->assertTrue($sucursal->es_principal);
        $this->assertEqualsCanonicalizing(
            ['venta', 'recepcion', 'almacenaje'],
            Localidad::where('sucursal_id', $sucursal->id)->pluck('tipo')->map->value->all(),
        );
    }

    public function test_el_stock_inicial_del_producto_queda_en_la_tienda(): void
    {
        $this->assertEquals(10, $this->stock($this->localidad($this->principal(), TipoLocalidad::VENTA)));
        $this->assertEquals(10, $this->totalLocalidades());
    }

    public function test_la_venta_descuenta_de_la_tienda_y_registra_la_localidad_en_el_kardex(): void
    {
        $tienda = $this->localidad($this->principal(), TipoLocalidad::VENTA);

        $this->vender(3);

        $this->assertEquals(7, $this->stock($tienda));
        $this->assertEquals(7, (float) $this->producto->fresh()->stock);
        $this->assertSame($tienda->id, \App\Models\MovimientoInventario::where('origen', OrigenMovimiento::VENTA)->sole()->localidad_id);
    }

    public function test_no_se_vende_lo_que_esta_en_el_almacen_si_la_tienda_no_tiene(): void
    {
        $almacen = $this->localidad($this->principal(), TipoLocalidad::ALMACENAJE);
        $this->entrada($almacen, 50);

        $this->expectException(StockInsuficienteException::class);
        $this->expectExceptionMessage('en Tienda');

        $this->vender(15); // la tienda tiene 10, el almacén 50
    }

    public function test_con_vender_sin_stock_solo_la_tienda_queda_en_negativo(): void
    {
        $this->empresaDefault->config()->update(['permite_stock_negativo' => true]);
        $tienda = $this->localidad($this->principal(), TipoLocalidad::VENTA);

        $this->vender(15);

        $this->assertEquals(-5, $this->stock($tienda));
        $this->assertEquals(-5, $this->totalLocalidades());
    }

    public function test_ningun_movimiento_que_no_sea_venta_deja_una_localidad_en_negativo(): void
    {
        $this->empresaDefault->config()->update(['permite_stock_negativo' => true]);
        $almacen = $this->localidad($this->principal(), TipoLocalidad::ALMACENAJE);

        $this->expectException(StockInsuficienteException::class);

        DB::transaction(fn () => app(InventarioService::class)->registrarMovimiento(
            $this->producto, TipoMovimiento::SALIDA, OrigenMovimiento::AJUSTE, '1', permitirNegativo: true, localidad: $almacen,
        ));
    }

    public function test_cada_sucursal_vende_de_su_propia_tienda(): void
    {
        $norte = $this->segundaSucursal();
        $tiendaNorte = $this->localidad($norte, TipoLocalidad::VENTA);
        $this->entrada($tiendaNorte, 4);

        $cajero = User::factory()->create(['empresa_id' => $this->empresaDefault->id, 'sucursal_id' => $norte->id]);
        $this->vender(4, $cajero);

        $this->assertEquals(0, $this->stock($tiendaNorte));
        $this->assertEquals(10, $this->stock($this->localidad($this->principal(), TipoLocalidad::VENTA)));
        $this->assertSame($norte->id, \App\Models\Venta::sole()->sucursal_id);
    }

    public function test_con_recepcion_activa_las_compras_entran_a_recibo_y_anular_saca_de_ahi(): void
    {
        $this->empresaDefault->config()->update(['compras_a_recepcion' => true]);
        $recibo = $this->localidad($this->principal(), TipoLocalidad::RECEPCION);
        $compra = $this->comprar(20);

        $this->assertEquals(20, $this->stock($recibo));
        $this->assertEquals(30, (float) $this->producto->fresh()->stock);
        $this->assertSame($this->principal()->id, $compra->sucursal_id);

        app(CompraService::class)->anular($compra, 'Error', $this->usuario()->id);

        $this->assertEquals(0, $this->stock($recibo));
        $this->assertEquals(10, $this->totalLocalidades());
    }

    public function test_sin_recepcion_las_compras_entran_directo_a_la_tienda(): void
    {
        $this->comprar(5);

        $this->assertEquals(15, $this->stock($this->localidad($this->principal(), TipoLocalidad::VENTA)));
    }

    public function test_la_compra_rechaza_una_localidad_de_otra_empresa(): void
    {
        $otra = Empresa::factory()->create();
        $ajena = Localidad::where('empresa_id', $otra->id)->firstOrFail();

        $this->expectException(RuntimeException::class);

        $this->comprar(5, $ajena->id);
    }

    public function test_un_cambio_directo_del_stock_total_se_refleja_en_la_tienda(): void
    {
        $this->producto->update(['stock' => 25]);

        $this->assertEquals(25, $this->stock($this->localidad($this->principal(), TipoLocalidad::VENTA)));
        $this->assertEquals(25, $this->totalLocalidades());
    }

    public function test_el_administrador_crea_una_sucursal_y_nace_con_sus_localidades(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $admin = User::factory()->create();
        app(\App\Services\RolesEmpresaService::class)->asignarAdministrador($admin, $this->empresaDefault);

        $this->actingAs($admin)->get(\App\Filament\Resources\SucursalResource::getUrl('index'))->assertOk();

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\SucursalResource\Pages\CreateSucursal::class)
            ->fillForm(['nombre' => 'Sucursal Norte', 'activa' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $norte = Sucursal::where('nombre', 'Sucursal Norte')->sole();
        $this->assertSame($this->empresaDefault->id, $norte->empresa_id);
        $this->assertCount(3, $norte->localidades);
    }

    public function test_no_se_pueden_tener_dos_localidades_de_venta_activas_en_una_sucursal(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        Localidad::create([
            'empresa_id' => $this->empresaDefault->id, 'sucursal_id' => $this->principal()->id,
            'nombre' => 'Otra tienda', 'tipo' => TipoLocalidad::VENTA->value,
        ]);
    }

    public function test_cada_empresa_solo_ve_sus_sucursales(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $admin = User::factory()->create();
        app(\App\Services\RolesEmpresaService::class)->asignarAdministrador($admin, $this->empresaDefault);
        $otra = Empresa::factory()->create();
        Sucursal::where('empresa_id', $otra->id)->update(['nombre' => 'Sucursal Ajena']);

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\SucursalResource\Pages\ListSucursales::class)
            ->assertCanSeeTableRecords(Sucursal::where('empresa_id', $this->empresaDefault->id)->get())
            ->assertCanNotSeeTableRecords(Sucursal::where('empresa_id', $otra->id)->get());
    }

    private function usuario(): User
    {
        return User::factory()->create(['empresa_id' => $this->empresaDefault->id]);
    }

    private function comprar(float $cantidad, ?int $localidadId = null): \App\Models\Compra
    {
        $proveedor = Proveedor::create([
            'nombre' => 'Proveedor', 'rnc' => '1300000'.random_int(10, 99), 'tipo' => TipoProveedor::FORMAL, 'activo' => true,
        ]);

        return app(CompraService::class)->crear([
            'proveedor_id' => $proveedor->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CREDITO_FISCAL_FISICA,
            'ncf' => 'B01'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'fecha' => now(),
            'itbis_incluido' => false,
            'localidad_id' => $localidadId,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => $cantidad, 'costo_unitario' => 400]],
        ], $this->usuario()->id, $this->empresaDefault);
    }
}
