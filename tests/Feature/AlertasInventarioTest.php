<?php

namespace Tests\Feature;

use App\Enums\OrigenMovimiento;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoLocalidad;
use App\Enums\TipoMovimiento;
use App\Enums\TipoProducto;
use App\Models\ArqueoCaja;
use App\Models\Localidad;
use App\Models\Producto;
use App\Models\SecuenciaNcf;
use App\Models\StockLocalidad;
use App\Models\User;
use App\Services\AlertaAntirroboService;
use App\Services\AlertaRecepcionService;
use App\Services\InventarioService;
use App\Services\LocalidadService;
use App\Services\RolesEmpresaService;
use App\Services\VentaService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Alerta de mercancía olvidada en recepción y señales de control antirrobo. */
class AlertasInventarioTest extends TestCase
{
    use RefreshDatabase;

    private Producto $producto;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create(['name' => 'Ana Cajera']);
        app(RolesEmpresaService::class)->asignarAdministrador($this->admin, $this->empresaDefault);

        SecuenciaNcf::create([
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO_FISICA->value, 'prefijo' => 'B02',
            'secuencia_desde' => 1, 'secuencia_actual' => 1, 'secuencia_hasta' => 1000, 'vencimiento' => now()->addYear(), 'activa' => true,
        ]);
        SecuenciaNcf::create([
            'tipo_comprobante' => TipoComprobante::NOTA_CREDITO_FISICA->value, 'prefijo' => 'B04',
            'secuencia_desde' => 1, 'secuencia_actual' => 1, 'secuencia_hasta' => 1000, 'vencimiento' => now()->addYear(), 'activa' => true,
        ]);

        $this->producto = Producto::create([
            'codigo' => 'CEM', 'nombre' => 'Cemento', 'tipo' => TipoProducto::PRODUCTO,
            'costo' => 400, 'precio' => 500, 'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true, 'stock' => 50, 'stock_minimo' => 0, 'activo' => true,
        ]);
    }

    private function recibo(): Localidad
    {
        return app(LocalidadService::class)->localidadDeRecepcion(app(LocalidadService::class)->principal($this->empresaDefault));
    }

    public function test_avisa_la_mercancia_que_lleva_dias_en_recibo_y_no_la_reciente(): void
    {
        DB::transaction(fn () => app(InventarioService::class)->registrarMovimiento(
            $this->producto, TipoMovimiento::ENTRADA, OrigenMovimiento::COMPRA, '10', localidad: $this->recibo(),
        ));
        $servicio = app(AlertaRecepcionService::class);

        $this->assertCount(0, $servicio->olvidada($this->empresaDefault));

        StockLocalidad::where('localidad_id', $this->recibo()->id)->update(['updated_at' => now()->subDays(5)]);

        $filas = $servicio->olvidada($this->empresaDefault);
        $this->assertCount(1, $filas);
        $this->assertStringContainsString('Cemento', $servicio->resumen($filas));
        $this->assertSame(1, $servicio->notificar($this->empresaDefault));
    }

    public function test_el_comando_diario_corre_sin_errores(): void
    {
        $this->artisan('inventario:alertas')->assertSuccessful();
    }

    public function test_senales_por_anulaciones_y_faltante_de_caja(): void
    {
        $this->empresaDefault->config()->update(['antirrobo_umbral_anulaciones' => 2]);
        $ventas = app(VentaService::class);

        foreach (range(1, 2) as $i) {
            $v = $ventas->registrar([
                'user_id' => $this->admin->id,
                'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO_FISICA->value,
                'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1]],
            ], $this->empresaDefault);
            $ventas->anular($v, 'Error de digitación', $this->admin->id, '04');
        }

        ArqueoCaja::create([
            'empresa_id' => $this->empresaDefault->id, 'user_id' => $this->admin->id, 'fondo_inicial' => 0,
            'abierto_en' => now()->subHours(8), 'cerrado_en' => now(), 'estado' => 'cerrado',
            'efectivo_esperado' => 5000, 'efectivo_contado' => 4700, 'diferencia' => -300,
        ]);

        $servicio = app(AlertaAntirroboService::class);
        $titulos = collect($servicio->senales($this->empresaDefault, now(), now()))->pluck('titulo')->all();

        $this->assertContains('Muchas anulaciones de ventas', $titulos);
        $this->assertContains('Faltantes en cierre de caja', $titulos);

        $fila = $servicio->ranking($this->empresaDefault, now(), now())->firstWhere('usuario', 'Ana Cajera');
        $this->assertSame(2, $fila['anulaciones']);
        $this->assertEquals(300, $fila['faltante_caja']);
    }

    public function test_por_debajo_del_umbral_no_hay_senal(): void
    {
        $ventas = app(VentaService::class);
        $v = $ventas->registrar([
            'user_id' => $this->admin->id,
            'tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO_FISICA->value,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1]],
        ], $this->empresaDefault);
        $ventas->anular($v, 'Error', $this->admin->id, '04');

        $this->assertSame($this->admin->id, $v->fresh()->anulada_por);
        $this->assertSame([], app(AlertaAntirroboService::class)->senales($this->empresaDefault, now(), now()));
    }

    public function test_el_reporte_de_control_carga_y_lo_ve_solo_quien_tiene_permiso(): void
    {
        $this->actingAs($this->admin)->get(\App\Filament\Pages\Reportes\ReporteControl::getUrl())->assertOk();

        $vendedor = User::factory()->create(['empresa_id' => $this->empresaDefault->id]);
        setPermissionsTeamId($this->empresaDefault->id);
        $vendedor->assignRole(\Spatie\Permission\Models\Role::where('empresa_id', $this->empresaDefault->id)->where('name', 'Vendedor')->first());

        $this->actingAs($vendedor->fresh())->get(\App\Filament\Pages\Reportes\ReporteControl::getUrl())->assertForbidden();
    }
}
