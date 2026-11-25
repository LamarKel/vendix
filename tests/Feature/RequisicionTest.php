<?php

namespace Tests\Feature;

use App\Enums\EstadoRequisicion;
use App\Enums\OrigenMovimiento;
use App\Enums\TasaItbis;
use App\Enums\TipoLocalidad;
use App\Enums\TipoMovimiento;
use App\Enums\TipoProducto;
use App\Exceptions\StockInsuficienteException;
use App\Models\Empresa;
use App\Models\Localidad;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\InventarioService;
use App\Services\LocalidadService;
use App\Services\RequisicionService;
use App\Services\RolesEmpresaService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Requisiciones (misma sucursal y entre sucursales) y movimientos internos: cada sucursal solo
 * mueve lo suyo, todo queda en el Kardex y lo que no llega queda como faltante.
 */
class RequisicionTest extends TestCase
{
    use RefreshDatabase;

    private Producto $producto;

    private Sucursal $principal;

    private Sucursal $norte;

    private RequisicionService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->svc = app(RequisicionService::class);

        $this->producto = Producto::create([
            'codigo' => 'CEM', 'nombre' => 'Cemento', 'tipo' => TipoProducto::PRODUCTO,
            'costo' => 400, 'precio' => 500, 'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => true, 'stock' => 0, 'stock_minimo' => 0, 'activo' => true,
        ]);

        $loc = app(LocalidadService::class);
        $this->principal = $loc->principal($this->empresaDefault);
        $this->norte = Sucursal::create(['empresa_id' => $this->empresaDefault->id, 'nombre' => 'Norte']);
        $loc->asegurarLocalidadesBase($this->norte);

        $this->entrada($this->loc($this->principal, TipoLocalidad::ALMACENAJE), 100);
        $this->entrada($this->loc($this->norte, TipoLocalidad::ALMACENAJE), 40);
    }

    private function loc(Sucursal $s, TipoLocalidad $tipo): Localidad
    {
        return Localidad::where('sucursal_id', $s->id)->where('tipo', $tipo->value)->firstOrFail();
    }

    private function entrada(Localidad $l, float $cantidad): void
    {
        DB::transaction(fn () => app(InventarioService::class)->registrarMovimiento(
            $this->producto, TipoMovimiento::ENTRADA, OrigenMovimiento::AJUSTE, (string) $cantidad, localidad: $l,
        ));
    }

    private function stock(Localidad $l): float
    {
        return (float) app(InventarioService::class)->stockEn($l, $this->producto);
    }

    private function usuario(string $rol, Sucursal $sucursal): User
    {
        $u = User::factory()->create(['empresa_id' => $this->empresaDefault->id, 'sucursal_id' => $sucursal->id]);
        setPermissionsTeamId($this->empresaDefault->id);
        $u->assignRole(Role::where('empresa_id', $this->empresaDefault->id)->where('name', $rol)->firstOrFail());

        return $u->fresh();
    }

    private function pedir(User $quien, Localidad $origen, Localidad $destino, float $cantidad = 20)
    {
        return $this->svc->crear($this->empresaDefault, $quien, $origen->id, $destino->id, [['producto_id' => $this->producto->id, 'cantidad' => $cantidad]]);
    }

    public function test_tienda_pide_al_almacen_de_su_sucursal_y_todo_queda_trazado(): void
    {
        $tienda = $this->loc($this->principal, TipoLocalidad::VENTA);
        $almacen = $this->loc($this->principal, TipoLocalidad::ALMACENAJE);
        $vendedor = $this->usuario('Vendedor', $this->principal);
        $almacenista = $this->usuario('Almacenista', $this->principal);

        $r = $this->pedir($vendedor, $almacen, $tienda);
        $this->assertSame(EstadoRequisicion::PENDIENTE, $r->estado);
        $this->assertFalse($r->entre_sucursales);
        $this->assertSame('REQ-00001', $r->etiquetaNumero());

        $d = $r->detalles->sole();
        $this->svc->despachar($r, $this->empresaDefault, $almacenista, [$d->id => 15], [$d->id => 'Solo había 15 a mano']);
        $this->assertEquals(85, $this->stock($almacen));
        $this->assertEquals(0, $this->stock($tienda)); // en tránsito
        $this->assertSame(EstadoRequisicion::DESPACHADA, $r->fresh()->estado);

        $this->svc->recibir($r->fresh(), $this->empresaDefault, $vendedor, [$d->id => 14], [$d->id => 'Un saco roto']);
        $this->assertEquals(14, $this->stock($tienda));

        $d->refresh();
        $this->assertEquals(1, (float) $d->faltante());
        $r->refresh();
        $this->assertSame(EstadoRequisicion::RECIBIDA, $r->estado);
        $this->assertSame($almacenista->id, $r->despachado_por);
        $this->assertSame($vendedor->id, $r->recibido_por);

        $kardex = MovimientoInventario::where('referencia_id', $r->id)->whereIn('origen', ['requisicion_despacho', 'requisicion_recepcion'])->get();
        $this->assertSame([$almacen->id, $tienda->id], $kardex->sortBy('id')->pluck('localidad_id')->values()->all());
    }

    public function test_entre_sucursales_cada_una_solo_opera_lo_suyo(): void
    {
        $tiendaPrincipal = $this->loc($this->principal, TipoLocalidad::VENTA);
        $almacenNorte = $this->loc($this->norte, TipoLocalidad::ALMACENAJE);
        $almacenistaPrincipal = $this->usuario('Almacenista', $this->principal);
        $almacenistaNorte = $this->usuario('Almacenista', $this->norte);

        $r = $this->pedir($almacenistaPrincipal, $almacenNorte, $tiendaPrincipal, 10);
        $this->assertTrue($r->entre_sucursales);
        $d = $r->detalles->sole();

        // La principal no puede despachar del almacén de Norte.
        try {
            $this->svc->despachar($r, $this->empresaDefault, $almacenistaPrincipal, [$d->id => 10]);
            $this->fail('La sucursal que pide no puede despachar del almacén ajeno.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('otra sucursal', $e->getMessage());
        }
        $this->assertEquals(40, $this->stock($almacenNorte));

        $this->svc->despachar($r, $this->empresaDefault, $almacenistaNorte, [$d->id => 10]);
        $this->assertEquals(30, $this->stock($almacenNorte));

        // Norte no puede recibir en la tienda de la principal.
        try {
            $this->svc->recibir($r->fresh(), $this->empresaDefault, $almacenistaNorte);
            $this->fail('Norte no puede recibir en la localidad de la principal.');
        } catch (RuntimeException) {
        }

        $this->svc->recibir($r->fresh(), $this->empresaDefault, $almacenistaPrincipal);
        $this->assertEquals(10, $this->stock($tiendaPrincipal));
    }

    public function test_no_se_puede_pedir_para_una_localidad_de_otra_sucursal(): void
    {
        $vendedorNorte = $this->usuario('Vendedor', $this->norte);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('otra sucursal');

        $this->pedir($vendedorNorte, $this->loc($this->principal, TipoLocalidad::ALMACENAJE), $this->loc($this->principal, TipoLocalidad::VENTA));
    }

    public function test_con_aprobacion_configurada_entre_sucursales_no_se_despacha_sin_aprobar(): void
    {
        $this->empresaDefault->config()->update(['requisicion_sucursal_requiere_aprobacion' => true]);
        $almacenistaPrincipal = $this->usuario('Almacenista', $this->principal);
        $almacenistaNorte = $this->usuario('Almacenista', $this->norte);
        $admin = $this->usuario('Administrador', $this->principal);

        $r = $this->pedir($almacenistaPrincipal, $this->loc($this->norte, TipoLocalidad::ALMACENAJE), $this->loc($this->principal, TipoLocalidad::VENTA), 5);
        $this->assertSame(EstadoRequisicion::PENDIENTE_APROBACION, $r->estado);

        try {
            $this->svc->despachar($r, $this->empresaDefault, $almacenistaNorte, []);
            $this->fail('No se despacha sin aprobar.');
        } catch (RuntimeException) {
        }

        // El almacenista no aprueba; el administrador sí.
        try {
            $this->svc->aprobar($r, $this->empresaDefault, $almacenistaNorte);
            $this->fail('Sin permiso de aprobar.');
        } catch (RuntimeException) {
        }

        $this->svc->aprobar($r, $this->empresaDefault, $admin);
        $this->svc->despachar($r->fresh(), $this->empresaDefault, $almacenistaNorte, []);
        $this->assertSame(EstadoRequisicion::DESPACHADA, $r->fresh()->estado);
    }

    public function test_dentro_de_la_sucursal_nunca_lleva_aprobacion(): void
    {
        $this->empresaDefault->config()->update(['requisicion_sucursal_requiere_aprobacion' => true]);
        $r = $this->pedir($this->usuario('Vendedor', $this->principal), $this->loc($this->principal, TipoLocalidad::ALMACENAJE), $this->loc($this->principal, TipoLocalidad::VENTA));

        $this->assertSame(EstadoRequisicion::PENDIENTE, $r->estado);
    }

    public function test_no_se_despacha_mas_de_lo_que_hay_en_el_origen(): void
    {
        $almacenista = $this->usuario('Almacenista', $this->principal);
        $r = $this->pedir($almacenista, $this->loc($this->principal, TipoLocalidad::ALMACENAJE), $this->loc($this->principal, TipoLocalidad::VENTA), 150);

        $this->expectException(StockInsuficienteException::class);
        $this->svc->despachar($r, $this->empresaDefault, $almacenista, []);
    }

    public function test_despachar_menos_exige_motivo_y_recibir_mas_de_lo_despachado_no_se_permite(): void
    {
        $almacenista = $this->usuario('Almacenista', $this->principal);
        $r = $this->pedir($almacenista, $this->loc($this->principal, TipoLocalidad::ALMACENAJE), $this->loc($this->principal, TipoLocalidad::VENTA), 20);
        $d = $r->detalles->sole();

        try {
            $this->svc->despachar($r, $this->empresaDefault, $almacenista, [$d->id => 10]);
            $this->fail('Despachar menos sin nota debe fallar.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('menos de lo pedido', $e->getMessage());
        }

        $this->svc->despachar($r->fresh(), $this->empresaDefault, $almacenista, [$d->id => 20]);

        $this->expectException(RuntimeException::class);
        $this->svc->recibir($r->fresh(), $this->empresaDefault, $almacenista, [$d->id => 25]);
    }

    public function test_no_se_despacha_dos_veces(): void
    {
        $almacenista = $this->usuario('Almacenista', $this->principal);
        $r = $this->pedir($almacenista, $this->loc($this->principal, TipoLocalidad::ALMACENAJE), $this->loc($this->principal, TipoLocalidad::VENTA), 10);
        $this->svc->despachar($r, $this->empresaDefault, $almacenista, []);

        try {
            $this->svc->despachar($r, $this->empresaDefault, $almacenista, []);
            $this->fail('Segundo despacho.');
        } catch (RuntimeException) {
        }

        $this->assertEquals(90, $this->stock($this->loc($this->principal, TipoLocalidad::ALMACENAJE)));
    }

    public function test_cancelar_solo_antes_de_despachar(): void
    {
        $vendedor = $this->usuario('Vendedor', $this->principal);
        $r = $this->pedir($vendedor, $this->loc($this->principal, TipoLocalidad::ALMACENAJE), $this->loc($this->principal, TipoLocalidad::VENTA));

        $this->svc->cancelar($r, $this->empresaDefault, $vendedor, 'Ya no hace falta');
        $this->assertSame(EstadoRequisicion::CANCELADA, $r->fresh()->estado);
        $this->assertEquals(100, $this->stock($this->loc($this->principal, TipoLocalidad::ALMACENAJE)));
    }

    public function test_movimiento_interno_de_recibo_a_almacen(): void
    {
        $recibo = $this->loc($this->principal, TipoLocalidad::RECEPCION);
        $almacen = $this->loc($this->principal, TipoLocalidad::ALMACENAJE);
        DB::transaction(fn () => app(InventarioService::class)->registrarMovimiento(
            $this->producto, TipoMovimiento::ENTRADA, OrigenMovimiento::COMPRA, '30', localidad: $recibo,
        ));
        $almacenista = $this->usuario('Almacenista', $this->principal);

        $mi = $this->svc->moverInterno($this->empresaDefault, $almacenista, $recibo->id, $almacen->id, [['producto_id' => $this->producto->id, 'cantidad' => 30]]);

        $this->assertSame('MI-00001', $mi->etiquetaNumero());
        $this->assertEquals(0, $this->stock($recibo));
        $this->assertEquals(130, $this->stock($almacen));
        $this->assertEquals(170, (float) $this->producto->fresh()->stock);
    }

    public function test_movimiento_interno_no_cruza_sucursales_ni_lo_hace_el_vendedor(): void
    {
        $almacenista = $this->usuario('Almacenista', $this->principal);

        try {
            $this->svc->moverInterno($this->empresaDefault, $almacenista, $this->loc($this->principal, TipoLocalidad::ALMACENAJE)->id, $this->loc($this->norte, TipoLocalidad::VENTA)->id, [['producto_id' => $this->producto->id, 'cantidad' => 1]]);
            $this->fail('No cruza sucursales.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('misma sucursal', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('permiso');
        $this->svc->moverInterno($this->empresaDefault, $this->usuario('Vendedor', $this->principal), $this->loc($this->principal, TipoLocalidad::ALMACENAJE)->id, $this->loc($this->principal, TipoLocalidad::VENTA)->id, [['producto_id' => $this->producto->id, 'cantidad' => 1]]);
    }

    public function test_no_usa_localidades_ni_productos_de_otra_empresa(): void
    {
        $otra = Empresa::factory()->create();
        $ajena = Localidad::where('empresa_id', $otra->id)->firstOrFail();

        $this->expectException(RuntimeException::class);
        $this->pedir($this->usuario('Administrador', $this->principal), $ajena, $this->loc($this->principal, TipoLocalidad::VENTA));
    }

    public function test_pantalla_completa_pedir_despachar_y_recibir(): void
    {
        $tienda = $this->loc($this->principal, TipoLocalidad::VENTA);
        $almacen = $this->loc($this->principal, TipoLocalidad::ALMACENAJE);
        $vendedor = $this->usuario('Vendedor', $this->principal);
        $almacenista = $this->usuario('Almacenista', $this->principal);

        $this->actingAs($vendedor)->get(\App\Filament\Resources\RequisicionResource::getUrl('index'))->assertOk();
        $undo = \Filament\Forms\Components\Repeater::fake();

        \Livewire\Livewire::actingAs($vendedor)
            ->test(\App\Filament\Resources\RequisicionResource\Pages\ListRequisiciones::class)
            ->callAction('nuevaRequisicion', data: [
                'localidad_destino_id' => $tienda->id,
                'localidad_origen_id' => $almacen->id,
                'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 12]],
            ])
            ->assertHasNoActionErrors();

        $r = \App\Models\Requisicion::sole();
        $this->actingAs($almacenista)->get(\App\Filament\Resources\RequisicionResource::getUrl('view', ['record' => $r]))->assertOk();

        \Livewire\Livewire::actingAs($almacenista)
            ->test(\App\Filament\Resources\RequisicionResource\Pages\ViewRequisicion::class, ['record' => $r->id])
            ->assertActionVisible('despachar')
            ->assertActionHidden('recibir')
            ->callAction('despachar')
            ->assertHasNoActionErrors();

        $this->assertEquals(88, $this->stock($almacen));

        \Livewire\Livewire::actingAs($vendedor)
            ->test(\App\Filament\Resources\RequisicionResource\Pages\ViewRequisicion::class, ['record' => $r->id])
            ->assertActionHidden('despachar')
            ->callAction('recibir')
            ->assertHasNoActionErrors();

        $this->assertEquals(12, $this->stock($tienda));
        $this->assertSame(EstadoRequisicion::RECIBIDA, $r->fresh()->estado);
        $undo();
    }

    public function test_una_sucursal_no_ve_requisiciones_en_las_que_no_participa(): void
    {
        $vendedorNorte = $this->usuario('Vendedor', $this->norte);
        $propia = $this->pedir($this->usuario('Vendedor', $this->principal), $this->loc($this->principal, TipoLocalidad::ALMACENAJE), $this->loc($this->principal, TipoLocalidad::VENTA));

        \Livewire\Livewire::actingAs($vendedorNorte)
            ->test(\App\Filament\Resources\RequisicionResource\Pages\ListRequisiciones::class)
            ->assertCanNotSeeTableRecords([$propia]);
    }

    public function test_de_otra_sucursal_solo_se_ve_el_total_y_de_la_propia_por_localidad(): void
    {
        $vendedor = $this->usuario('Vendedor', $this->principal);
        $this->actingAs($vendedor);

        $propia = \App\Filament\Resources\RequisicionResource::existenciaVisible($this->loc($this->principal, TipoLocalidad::ALMACENAJE)->id, $this->producto->id);
        $ajena = \App\Filament\Resources\RequisicionResource::existenciaVisible($this->loc($this->norte, TipoLocalidad::ALMACENAJE)->id, $this->producto->id);

        $this->assertSame('Disponible en Almacén: 100.00', $propia);
        $this->assertSame('Total en Norte: 40.00', $ajena);
    }

    public function test_a_recibo_solo_entra_por_compra_y_lo_que_sale_no_vuelve(): void
    {
        $recibo = $this->loc($this->principal, TipoLocalidad::RECEPCION);
        $almacen = $this->loc($this->principal, TipoLocalidad::ALMACENAJE);
        $almacenista = $this->usuario('Almacenista', $this->principal);

        // Un ajuste no puede sumar en Recibo.
        try {
            $this->entrada($recibo, 5);
            $this->fail('Un ajuste no debe entrar a Recibo.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('solo entra mercancía al registrar una compra', $e->getMessage());
        }

        // Ni un movimiento interno ni una requisición pueden tener Recibo como destino.
        foreach ([
            fn () => $this->svc->moverInterno($this->empresaDefault, $almacenista, $almacen->id, $recibo->id, [['producto_id' => $this->producto->id, 'cantidad' => 1]]),
            fn () => $this->pedir($almacenista, $almacen, $recibo, 1),
        ] as $intento) {
            try {
                $intento();
                $this->fail('Recibo no puede ser destino.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('recepción', $e->getMessage());
            }
        }

        // Una compra sí entra, y de ahí se puede sacar al almacén.
        DB::transaction(fn () => app(InventarioService::class)->registrarMovimiento(
            $this->producto, TipoMovimiento::ENTRADA, OrigenMovimiento::COMPRA, '8', localidad: $recibo,
        ));
        $this->svc->moverInterno($this->empresaDefault, $almacenista, $recibo->id, $almacen->id, [['producto_id' => $this->producto->id, 'cantidad' => 8]]);

        $this->assertEquals(0, $this->stock($recibo));
        $this->assertEquals(108, $this->stock($almacen));
        $this->assertEquals(0, MovimientoInventario::where('localidad_id', $recibo->id)->where('tipo', 'entrada')->where('origen', '!=', 'compra')->count());
    }
}
