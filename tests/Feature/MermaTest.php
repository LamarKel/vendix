<?php

namespace Tests\Feature;

use App\Enums\Modulo;
use App\Enums\MotivoMerma;
use App\Enums\OrigenMovimiento;
use App\Enums\TasaItbis;
use App\Enums\TipoMovimiento;
use App\Enums\TipoProducto;
use App\Enums\TipoVenta;
use App\Exceptions\MermaInvalidaException;
use App\Exceptions\StockInsuficienteException;
use App\Filament\Pages\Reportes\ReporteMargenProducto;
use App\Filament\Resources\MermaResource;
use App\Filament\Resources\MermaResource\Pages\ListMermas;
use App\Models\Empresa;
use App\Models\EmpresaModulo;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Role;
use App\Models\User;
use App\Services\MermaService;
use App\Services\ReporteService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MermaTest extends TestCase
{
    use RefreshDatabase;

    private Producto $tomate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tomate = $this->producto('VER-001', 'Tomate', costo: 20, stock: 150);
    }

    private function producto(string $codigo, string $nombre, float $costo = 20, float $stock = 100, array $extra = []): Producto
    {
        return Producto::create(array_merge([
            'empresa_id' => $this->empresaDefault->id,
            'codigo' => $codigo,
            'nombre' => $nombre,
            'tipo' => TipoProducto::PRODUCTO,
            'tipo_venta' => TipoVenta::PESADO,
            'unidad_base' => 'libra',
            'costo' => $costo,
            'precio' => $costo * 2,
            'precio_por_peso' => $costo * 2,
            'tasa_itbis' => TasaItbis::EXENTO,
            'controla_stock' => true,
            'stock' => $stock,
            'stock_minimo' => 0,
            'activo' => true,
        ], $extra));
    }

    private function usuarioCon(string $rol): User
    {
        $usuario = User::factory()->create(['empresa_id' => $this->empresaDefault->id]);
        $usuario->assignRole($rol);

        return $usuario;
    }

    private function servicio(): MermaService
    {
        return app(MermaService::class);
    }

    // ── Servicio ───────────────────────────────────────────────────────────

    public function test_registrar_baja_el_stock_y_guarda_motivo_y_costo_en_el_kardex(): void
    {
        $papa = $this->producto('VIV-001', 'Papa', costo: 12.5, stock: 300);

        $mermas = $this->servicio()->registrar($this->empresaDefault, [
            ['producto_id' => $this->tomate->id, 'cantidad' => '8.5', 'motivo' => 'danado', 'nota' => 'Caja aplastada'],
            ['producto_id' => $papa->id, 'cantidad' => 3, 'motivo' => MotivoMerma::VENCIDO],
        ], userId: null, observacion: 'Cierre del sábado');

        $this->assertCount(2, $mermas);
        $this->assertSame('141.500', (string) $this->tomate->refresh()->stock);
        $this->assertSame('297.000', (string) $papa->refresh()->stock);

        $tomate = $mermas->first();
        $this->assertSame(TipoMovimiento::SALIDA, $tomate->tipo);
        $this->assertSame(OrigenMovimiento::MERMA, $tomate->origen);
        $this->assertSame(MotivoMerma::DANADO, $tomate->motivo);
        $this->assertSame('20.00', (string) $tomate->costo_unitario);
        $this->assertSame('170.00', $tomate->valorPerdido());
        $this->assertSame('Caja aplastada', $tomate->observacion);
        $this->assertSame($this->empresaDefault->id, $tomate->empresa_id);

        // Sin nota propia, la línea lleva la nota general.
        $this->assertSame('Cierre del sábado', $mermas->last()->observacion);
        $this->assertSame('37.50', $mermas->last()->valorPerdido());
    }

    public function test_el_costo_guardado_no_cambia_si_despues_sube_el_costo_del_producto(): void
    {
        $merma = $this->servicio()->registrar($this->empresaDefault, [
            ['producto_id' => $this->tomate->id, 'cantidad' => 10, 'motivo' => 'danado'],
        ])->first();

        $this->tomate->update(['costo' => 35]);

        $this->assertSame('200.00', $merma->fresh()->valorPerdido());
    }

    public function test_es_todo_o_nada_si_una_linea_no_tiene_stock(): void
    {
        $limon = $this->producto('FRU-007', 'Limón', costo: 3, stock: 5);

        try {
            $this->servicio()->registrar($this->empresaDefault, [
                ['producto_id' => $this->tomate->id, 'cantidad' => 10, 'motivo' => 'danado'],
                ['producto_id' => $limon->id, 'cantidad' => 6, 'motivo' => 'danado'],
            ]);
            $this->fail('Debió rechazar la merma por falta de stock.');
        } catch (StockInsuficienteException) {
        }

        $this->assertSame('150.000', (string) $this->tomate->refresh()->stock);
        $this->assertSame(0, MovimientoInventario::count());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function lineasInvalidas(): array
    {
        return [
            'cantidad cero' => [['cantidad' => 0, 'motivo' => 'danado'], 'mayor que cero'],
            'cantidad negativa' => [['cantidad' => -2, 'motivo' => 'danado'], 'mayor que cero'],
            'cantidad vacía' => [['cantidad' => '', 'motivo' => 'danado'], 'mayor que cero'],
            'sin motivo' => [['cantidad' => 1, 'motivo' => null], 'motivo'],
            'motivo inventado' => [['cantidad' => 1, 'motivo' => 'regalo'], 'motivo'],
            'motivo no ofrecido' => [['cantidad' => 1, 'motivo' => 'faltante'], 'motivo'],
        ];
    }

    #[DataProvider('lineasInvalidas')]
    public function test_rechaza_lineas_invalidas(array $linea, string $mensaje): void
    {
        $this->expectException(MermaInvalidaException::class);
        $this->expectExceptionMessage($mensaje);

        $this->servicio()->registrar($this->empresaDefault, [['producto_id' => $this->tomate->id] + $linea]);
    }

    public function test_rechaza_lista_vacia_y_productos_sin_inventario(): void
    {
        $delivery = $this->producto('SRV-001', 'Delivery', extra: ['tipo' => TipoProducto::SERVICIO, 'controla_stock' => false, 'stock' => 0]);

        try {
            $this->servicio()->registrar($this->empresaDefault, []);
            $this->fail('Debió rechazar una merma sin productos.');
        } catch (MermaInvalidaException $e) {
            $this->assertStringContainsString('al menos un producto', $e->getMessage());
        }

        $this->expectException(MermaInvalidaException::class);
        $this->expectExceptionMessage('no lleva inventario');

        $this->servicio()->registrar($this->empresaDefault, [['producto_id' => $delivery->id, 'cantidad' => 1, 'motivo' => 'otro']]);
    }

    public function test_anular_devuelve_el_stock_al_mismo_costo_y_no_se_puede_anular_dos_veces(): void
    {
        $merma = $this->servicio()->registrar($this->empresaDefault, [
            ['producto_id' => $this->tomate->id, 'cantidad' => 10, 'motivo' => 'otro'],
        ])->first();

        $this->tomate->update(['costo' => 30]);

        $reversion = $this->servicio()->anular($merma, $this->empresaDefault, 'Se encontró en el almacén');

        $this->assertSame('150.000', (string) $this->tomate->refresh()->stock);
        $this->assertSame(TipoMovimiento::ENTRADA, $reversion->tipo);
        $this->assertSame(OrigenMovimiento::MERMA, $reversion->origen);
        $this->assertSame($merma->id, $reversion->revierte_movimiento_id);
        $this->assertSame('20.00', (string) $reversion->costo_unitario);
        $this->assertStringContainsString('Se encontró en el almacén', $reversion->observacion);

        $this->expectException(MermaInvalidaException::class);
        $this->expectExceptionMessage('ya fue anulada');

        $this->servicio()->anular($merma, $this->empresaDefault, 'Otra vez');
    }

    public function test_no_anula_sin_razon_ni_movimientos_que_no_son_mermas_manuales(): void
    {
        $merma = $this->servicio()->registrar($this->empresaDefault, [
            ['producto_id' => $this->tomate->id, 'cantidad' => 1, 'motivo' => 'danado'],
        ])->first();

        try {
            $this->servicio()->anular($merma, $this->empresaDefault, '   ');
            $this->fail('Debió exigir la razón.');
        } catch (MermaInvalidaException $e) {
            $this->assertStringContainsString('por qué', $e->getMessage());
        }

        // Una merma de devolución de cliente (lleva referencia a la nota de crédito y no motivo).
        $deDevolucion = MovimientoInventario::create([
            'empresa_id' => $this->empresaDefault->id,
            'producto_id' => $this->tomate->id,
            'tipo' => TipoMovimiento::SALIDA,
            'origen' => OrigenMovimiento::MERMA,
            'referencia_id' => 999,
            'cantidad' => 1,
            'stock_anterior' => 149,
            'stock_nuevo' => 148,
        ]);

        $this->expectException(MermaInvalidaException::class);
        $this->expectExceptionMessage('pantalla de Mermas');

        $this->servicio()->anular($deDevolucion, $this->empresaDefault, 'No aplica');
    }

    // ── Pantalla ───────────────────────────────────────────────────────────

    public function test_el_almacenista_registra_mermas_desde_la_pantalla(): void
    {
        $undo = Repeater::fake();
        $almacenista = $this->usuarioCon('Almacenista');

        Livewire::actingAs($almacenista)
            ->test(ListMermas::class)
            ->callAction('registrarMermas', data: [
                'lineas' => [
                    ['producto_id' => $this->tomate->id, 'cantidad' => '4.25', 'motivo' => 'danado', 'nota' => null],
                ],
                'observacion' => 'Cierre',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Merma registrada');

        $undo();

        $merma = MovimientoInventario::sole();
        $this->assertSame($almacenista->id, $merma->user_id);
        $this->assertSame('145.750', (string) $this->tomate->refresh()->stock);

        Livewire::actingAs($almacenista)
            ->test(ListMermas::class)
            ->assertCanSeeTableRecords([$merma])
            ->assertSee('Dañado o podrido')
            ->assertSee('85.00');
    }

    public function test_sin_stock_suficiente_avisa_y_no_registra(): void
    {
        $undo = Repeater::fake();

        Livewire::actingAs($this->usuarioCon('Almacenista'))
            ->test(ListMermas::class)
            ->callAction('registrarMermas', data: [
                'lineas' => [['producto_id' => $this->tomate->id, 'cantidad' => 500, 'motivo' => 'danado', 'nota' => null]],
            ])
            ->assertNotified('Stock insuficiente para «Tomate»: disponible 150.000, solicitado 500.000.');

        $undo();

        $this->assertSame(0, MovimientoInventario::count());
    }

    public function test_solo_el_administrador_anula_y_la_lista_marca_la_anulada(): void
    {
        $merma = $this->servicio()->registrar($this->empresaDefault, [
            ['producto_id' => $this->tomate->id, 'cantidad' => 2, 'motivo' => 'danado'],
        ])->first();

        Livewire::actingAs($this->usuarioCon('Almacenista'))
            ->test(ListMermas::class)
            ->assertTableActionHidden('anular', $merma);

        Livewire::actingAs($this->usuarioCon('Administrador'))
            ->test(ListMermas::class)
            ->assertTableActionVisible('anular', $merma)
            ->callTableAction('anular', $merma, data: ['razon' => 'Error al digitar'])
            ->assertHasNoTableActionErrors()
            ->assertTableActionHidden('anular', $merma)
            ->assertSee('Anulada');

        $this->assertSame('150.000', (string) $this->tomate->refresh()->stock);
        // La lista muestra solo las salidas: la reversión no aparece como otra merma.
        $this->assertSame(2, MovimientoInventario::count());
    }

    public function test_el_total_perdido_no_cuenta_las_anuladas(): void
    {
        $mermas = $this->servicio()->registrar($this->empresaDefault, [
            ['producto_id' => $this->tomate->id, 'cantidad' => 5, 'motivo' => 'danado'],
            ['producto_id' => $this->tomate->id, 'cantidad' => 2, 'motivo' => 'vencido'],
        ]);
        $this->servicio()->anular($mermas->last(), $this->empresaDefault, 'Error');

        $query = MermaResource::getEloquentQuery()->getQuery();

        $this->assertEqualsWithDelta(100.0, MermaResource::totalPerdido($query), 0.001);
    }

    public function test_acceso_por_permiso_y_por_modulo(): void
    {
        $this->actingAs($this->usuarioCon('Vendedor'))
            ->get(MermaResource::getUrl('index', tenant: $this->empresaDefault))
            ->assertForbidden();

        $almacenista = $this->usuarioCon('Almacenista');

        $this->actingAs($almacenista);
        $this->assertTrue(MermaResource::canAccess());

        EmpresaModulo::updateOrCreate(
            ['empresa_id' => $this->empresaDefault->id, 'modulo' => Modulo::INVENTARIO_MERMAS],
            ['habilitado' => false],
        );
        // tieneModulo() guarda los módulos en memoria por instancia: se usa una instancia fresca.
        \Filament\Facades\Filament::setTenant($this->empresaDefault->fresh(), isQuiet: true);

        $this->assertFalse(MermaResource::canAccess());
    }

    // ── Reporte de margen ──────────────────────────────────────────────────

    public function test_margen_por_producto_resta_la_merma_vigente_del_rango(): void
    {
        $this->empresaDefault->config()->update(['permite_ventas_sin_comprobante' => true]);

        app(\App\Services\VentaService::class)->registrar([
            'sin_comprobante' => true,
            'forma_pago' => \App\Enums\FormaPago::EFECTIVO,
            'lineas' => [['producto_id' => $this->tomate->id, 'cantidad' => 10]],
        ], $this->empresaDefault);

        $mermas = $this->servicio()->registrar($this->empresaDefault, [
            ['producto_id' => $this->tomate->id, 'cantidad' => 3, 'motivo' => 'danado'],
            ['producto_id' => $this->tomate->id, 'cantidad' => 1, 'motivo' => 'danado'],
        ]);
        $this->servicio()->anular($mermas->last(), $this->empresaDefault, 'Error');

        $fila = app(ReporteService::class)
            ->margenPorProductoQuery(now()->subDay(), now()->addDay(), $this->empresaDefault->id)
            ->first();

        // Venta: 10 lb × 40 = 400, costo 10 × 20 = 200 → ganancia 200. Merma vigente: 3 × 20 = 60.
        $this->assertEquals(200, (float) $fila->ganancia);
        $this->assertEquals(60, (float) $fila->merma);
        $this->assertEquals(140, (float) $fila->ganancia_real);

        // Fuera del rango no cuenta.
        $antes = app(ReporteService::class)
            ->margenPorProductoQuery(now()->subDays(10), now()->subDays(5), $this->empresaDefault->id)
            ->first();
        $this->assertNull($antes);
    }

    public function test_la_pagina_de_margen_muestra_merma_y_ganancia_real(): void
    {
        $admin = $this->usuarioCon('Administrador');

        $this->actingAs($admin);

        Livewire::test(ReporteMargenProducto::class)
            ->assertSee('Merma')
            ->assertSee('Ganancia real');
    }
}
