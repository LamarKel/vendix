<?php

namespace Tests\Feature;

use App\Enums\EstadoConteo;
use App\Enums\OrigenMovimiento;
use App\Enums\TasaItbis;
use App\Enums\TipoComprobante;
use App\Enums\TipoConteo;
use App\Enums\TipoLocalidad;
use App\Enums\TipoMovimiento;
use App\Enums\TipoProducto;
use App\Models\Conteo;
use App\Models\Localidad;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\SecuenciaNcf;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\ConteoService;
use App\Services\InventarioService;
use App\Services\LocalidadService;
use App\Services\VentaService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Conteos físicos: a ciegas, con reconteo, aprobación con umbral y ajuste por localidad. */
class ConteoTest extends TestCase
{
    use RefreshDatabase;

    private Producto $cemento;

    private Producto $varilla;

    private Sucursal $principal;

    private ConteoService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->svc = app(ConteoService::class);
        $this->principal = app(LocalidadService::class)->principal($this->empresaDefault);

        $this->cemento = Producto::create(['codigo' => 'CEM', 'nombre' => 'Cemento', 'tipo' => TipoProducto::PRODUCTO, 'costo' => 400, 'precio' => 500,
            'tasa_itbis' => TasaItbis::DIECIOCHO, 'controla_stock' => true, 'stock' => 25, 'stock_minimo' => 0, 'activo' => true]);
        $this->varilla = Producto::create(['codigo' => 'VAR', 'nombre' => 'Varilla', 'tipo' => TipoProducto::PRODUCTO, 'costo' => 300, 'precio' => 380,
            'tasa_itbis' => TasaItbis::DIECIOCHO, 'controla_stock' => true, 'stock' => 40, 'stock_minimo' => 0, 'activo' => true]);
    }

    private function tienda(): Localidad
    {
        return Localidad::where('sucursal_id', $this->principal->id)->where('tipo', TipoLocalidad::VENTA->value)->firstOrFail();
    }

    private function usuario(string $rol, ?Sucursal $sucursal = null): User
    {
        $u = User::factory()->create(['empresa_id' => $this->empresaDefault->id, 'sucursal_id' => ($sucursal ?? $this->principal)->id]);
        setPermissionsTeamId($this->empresaDefault->id);
        $u->assignRole(Role::where('empresa_id', $this->empresaDefault->id)->where('name', $rol)->firstOrFail());

        return $u->fresh();
    }

    private function stock(Producto $p): float
    {
        return (float) app(InventarioService::class)->stockEn($this->tienda(), $p);
    }

    private function conteoTotal(User $contador, ?User $admin = null): Conteo
    {
        return $this->svc->crear($this->empresaDefault, $admin ?? $this->usuario('Administrador'), $this->tienda()->id, TipoConteo::TOTAL, $contador->id);
    }

    private function lineas(Conteo $c, array $porProducto): array
    {
        return $c->detalles()->get()->mapWithKeys(fn ($d) => [$d->id => $porProducto[$d->producto_id]])->all();
    }

    public function test_flujo_completo_cuenta_revisa_y_ajusta_solo_las_diferencias(): void
    {
        $contador = $this->usuario('Almacenista');
        $admin = $this->usuario('Administrador');
        $c = $this->conteoTotal($contador, $admin);
        $this->assertSame('CF-00001', $c->etiquetaNumero());
        $this->assertCount(2, $c->detalles);

        $this->svc->registrar($c, $this->empresaDefault, $contador, $this->lineas($c, [$this->cemento->id => 22, $this->varilla->id => 40]));
        $this->svc->cerrar($c, $this->empresaDefault, $contador);
        $c->refresh();
        $this->assertSame(EstadoConteo::EN_REVISION, $c->estado);
        $this->assertEquals(-1200, (float) $c->valor_diferencia); // -3 × 400

        $this->svc->aprobar($c, $this->empresaDefault, $admin);
        $this->assertEquals(22, $this->stock($this->cemento));
        $this->assertEquals(40, $this->stock($this->varilla));
        $this->assertEquals(22, (float) $this->cemento->fresh()->stock);

        $mov = MovimientoInventario::where('origen', OrigenMovimiento::CONTEO)->sole();
        $this->assertSame($this->cemento->id, $mov->producto_id);
        $this->assertSame($this->tienda()->id, $mov->localidad_id);
        $this->assertSame($admin->id, $mov->user_id);
    }

    public function test_se_cuenta_con_la_tienda_abierta_lo_vendido_despues_no_cambia_la_diferencia(): void
    {
        SecuenciaNcf::create(['tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO_FISICA->value, 'prefijo' => 'B02', 'secuencia_desde' => 1,
            'secuencia_actual' => 1, 'secuencia_hasta' => 1000, 'vencimiento' => now()->addYear(), 'activa' => true]);
        $contador = $this->usuario('Almacenista');
        $admin = $this->usuario('Administrador');
        $c = $this->conteoTotal($contador, $admin);

        // Cuenta 22 de cemento cuando el sistema decía 25 (faltan 3).
        $this->svc->registrar($c, $this->empresaDefault, $contador, $this->lineas($c, [$this->cemento->id => 22, $this->varilla->id => 40]));

        // Después de contar, se venden 5.
        app(VentaService::class)->registrar(['tipo_comprobante' => TipoComprobante::FACTURA_CONSUMO_FISICA->value,
            'lineas' => [['producto_id' => $this->cemento->id, 'cantidad' => 5]]], $this->empresaDefault);

        $this->svc->cerrar($c, $this->empresaDefault, $contador);
        $this->svc->aprobar($c, $this->empresaDefault, $admin);

        // 25 − 5 vendidos − 3 de faltante = 17 (no 22).
        $this->assertEquals(17, $this->stock($this->cemento));
    }

    public function test_solo_el_asignado_cuenta_y_no_cierra_con_lineas_sin_contar(): void
    {
        $contador = $this->usuario('Almacenista');
        $otro = $this->usuario('Vendedor');
        $c = $this->conteoTotal($contador);

        try {
            $this->svc->registrar($c, $this->empresaDefault, $otro, $this->lineas($c, [$this->cemento->id => 1, $this->varilla->id => 1]));
            $this->fail('Solo el asignado cuenta.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('asignado a otra persona', $e->getMessage());
        }

        $d = $c->detalles()->where('producto_id', $this->cemento->id)->first();
        $this->svc->registrar($c, $this->empresaDefault, $contador, [$d->id => 25]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Faltan 1');
        $this->svc->cerrar($c, $this->empresaDefault, $contador);
    }

    public function test_reconteo_reasignado_reemplaza_el_primer_conteo(): void
    {
        $contador = $this->usuario('Almacenista');
        $segundo = $this->usuario('Almacenista');
        $admin = $this->usuario('Administrador');
        $c = $this->conteoTotal($contador, $admin);

        $this->svc->registrar($c, $this->empresaDefault, $contador, $this->lineas($c, [$this->cemento->id => 10, $this->varilla->id => 40]));
        $this->svc->cerrar($c, $this->empresaDefault, $contador);

        $d = $c->detalles()->where('producto_id', $this->cemento->id)->first();
        $this->svc->pedirReconteo($c, $this->empresaDefault, $admin, [$d->id], $segundo->id);
        $c->refresh();
        $this->assertSame(EstadoConteo::EN_CONTEO, $c->estado);
        $this->assertSame($segundo->id, $c->asignado_a);

        $this->svc->registrar($c, $this->empresaDefault, $segundo, [$d->id => 24]);
        $this->svc->cerrar($c, $this->empresaDefault, $segundo);
        $this->svc->aprobar($c, $this->empresaDefault, $admin);

        $this->assertEquals(24, $this->stock($this->cemento));
        $this->assertSame(2, $d->fresh()->veces_contado);
    }

    public function test_diferencias_grandes_las_aprueba_solo_el_administrador(): void
    {
        $this->empresaDefault->config()->update(['conteo_umbral_aprobacion_admin' => 1000]);
        $contador = $this->usuario('Almacenista');
        $encargado = $this->usuario('Almacenista');
        Role::where('empresa_id', $this->empresaDefault->id)->where('name', 'Almacenista')->first()->givePermissionTo('conteos.aprobar');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $c = $this->conteoTotal($contador);

        $this->svc->registrar($c, $this->empresaDefault, $contador, $this->lineas($c, [$this->cemento->id => 20, $this->varilla->id => 40])); // -2000
        $this->svc->cerrar($c, $this->empresaDefault, $contador);

        try {
            $this->svc->aprobar($c, $this->empresaDefault, $encargado->fresh());
            $this->fail('Por encima del umbral lo aprueba el administrador.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('administrador', $e->getMessage());
        }

        $this->svc->aprobar($c, $this->empresaDefault, $this->usuario('Administrador'));
        $this->assertEquals(20, $this->stock($this->cemento));
    }

    public function test_un_solo_conteo_abierto_por_localidad_y_no_se_cuenta_otra_sucursal(): void
    {
        $contador = $this->usuario('Almacenista');
        $this->conteoTotal($contador);

        try {
            $this->conteoTotal($contador);
            $this->fail('Ya hay uno abierto.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Ya hay un conteo abierto', $e->getMessage());
        }

        $norte = Sucursal::create(['empresa_id' => $this->empresaDefault->id, 'nombre' => 'Norte']);
        app(LocalidadService::class)->asegurarLocalidadesBase($norte);
        $encargadoNorte = $this->usuario('Almacenista', $norte);
        Role::where('empresa_id', $this->empresaDefault->id)->where('name', 'Almacenista')->first()->givePermissionTo('conteos.crear');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('otra sucursal');
        $this->svc->crear($this->empresaDefault, $encargadoNorte->fresh(), Localidad::where('sucursal_id', $this->principal->id)->where('tipo', 'almacenaje')->first()->id, TipoConteo::TOTAL, $encargadoNorte->id);
    }

    public function test_la_propuesta_ciclica_prioriza_lo_que_tuvo_diferencias_y_salta_lo_recien_contado(): void
    {
        $contador = $this->usuario('Almacenista');
        $admin = $this->usuario('Administrador');

        $c = $this->conteoTotal($contador, $admin);
        $this->svc->registrar($c, $this->empresaDefault, $contador, $this->lineas($c, [$this->cemento->id => 24, $this->varilla->id => 40]));
        $this->svc->cerrar($c, $this->empresaDefault, $contador);
        $this->svc->aprobar($c, $this->empresaDefault, $admin);

        // Recién contados los dos: la propuesta los salta.
        $this->assertSame([], $this->svc->sugerirCiclico($this->empresaDefault, $this->tienda()));

        DB::table('detalle_conteos')->update(['contado_en' => now()->subDays(40)]);
        $this->assertSame([$this->cemento->id, $this->varilla->id], $this->svc->sugerirCiclico($this->empresaDefault, $this->tienda()));
    }

    public function test_el_conteo_de_recibo_no_puede_sumar(): void
    {
        $recibo = Localidad::where('sucursal_id', $this->principal->id)->where('tipo', TipoLocalidad::RECEPCION->value)->first();
        DB::transaction(fn () => app(InventarioService::class)->registrarMovimiento($this->cemento, TipoMovimiento::ENTRADA, OrigenMovimiento::COMPRA, '5', localidad: $recibo));
        $contador = $this->usuario('Almacenista');
        $admin = $this->usuario('Administrador');
        $c = $this->svc->crear($this->empresaDefault, $admin, $recibo->id, TipoConteo::SELECCION, $contador->id, [$this->cemento->id]);

        $this->svc->registrar($c, $this->empresaDefault, $contador, [$c->detalles()->first()->id => 7]);
        $this->svc->cerrar($c, $this->empresaDefault, $contador);

        $this->expectException(RuntimeException::class);
        $this->svc->aprobar($c, $this->empresaDefault, $admin);
    }

    public function test_pantallas_crear_contar_a_ciegas_y_aprobar(): void
    {
        $contador = $this->usuario('Almacenista');
        $admin = $this->usuario('Administrador');
        $undo = \Filament\Forms\Components\Repeater::fake();

        $this->actingAs($admin)->get(\App\Filament\Resources\ConteoResource::getUrl('index'))->assertOk();

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\ConteoResource\Pages\ListConteos::class)
            ->callAction('nuevoConteo', data: ['localidad_id' => $this->tienda()->id, 'tipo' => 'total', 'asignado_a' => $contador->id])
            ->assertHasNoActionErrors();

        $c = Conteo::sole();

        // A ciegas: mientras se cuenta, la pantalla no muestra la existencia del sistema (25 y 40).
        \Livewire\Livewire::actingAs($contador)
            ->test(\App\Filament\Resources\ConteoResource\Pages\ViewConteo::class, ['record' => $c->id])
            ->assertActionVisible('contar')
            ->assertActionHidden('aprobar')
            ->assertDontSee('Sistema')
            ->assertDontSee('25.000')
            ->callAction('contar', data: ['lineas' => $c->detalles->map(fn ($d) => ['detalle_id' => $d->id, 'cantidad' => $d->producto_id === $this->cemento->id ? 23 : 40])->values()->all()])
            ->assertHasNoActionErrors()
            ->callAction('cerrar')
            ->assertHasNoActionErrors();

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\ConteoResource\Pages\ViewConteo::class, ['record' => $c->id])
            ->assertSee('Sistema')
            ->callAction('aprobar')
            ->assertHasNoActionErrors();

        $this->assertEquals(23, $this->stock($this->cemento));
        $undo();
    }
}
