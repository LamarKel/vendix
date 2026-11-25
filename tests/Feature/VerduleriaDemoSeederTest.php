<?php

namespace Tests\Feature;

use App\Enums\FormaPago;
use App\Enums\TasaItbis;
use App\Enums\TipoPago;
use App\Enums\TipoProducto;
use App\Enums\TipoVenta;
use App\Models\Cliente;
use App\Models\CuentaPorCobrar;
use App\Models\DetalleVenta;
use App\Models\Empresa;
use App\Models\ListaPrecio;
use App\Models\Producto;
use App\Models\ProductoPresentacion;
use App\Models\Promocion;
use App\Models\User;
use App\Models\Venta;
use App\Services\VentaService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VerduleriaDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La verdulería demo se siembra en sus tres escenarios fiscales con todas las formas de vender
 * del negocio (peso, unidad, presentaciones, sacos, exento/18 %/16 %, lista mayorista,
 * promociones, fiado) y sus ventas cuadran.
 */
class VerduleriaDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private const ESCENARIOS = ['informal', 'tipo-b', 'ecf'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolePermissionSeeder::class, VerduleriaDemoSeeder::class]);
    }

    private function empresa(string $escenario): Empresa
    {
        return Empresa::where('slug', "demo-verduleria-{$escenario}")->firstOrFail();
    }

    private function producto(Empresa $empresa, string $codigo): Producto
    {
        return Producto::where('empresa_id', $empresa->id)->where('codigo', $codigo)->firstOrFail();
    }

    public function test_crea_las_tres_empresas_y_es_idempotente(): void
    {
        $conteo = fn () => [
            Empresa::count(), User::count(), Producto::count(), ProductoPresentacion::count(),
            Cliente::count(), ListaPrecio::count(), Promocion::count(), Venta::count(),
            DetalleVenta::count(), CuentaPorCobrar::count(),
        ];
        $primera = $conteo();

        $this->assertSame(3, Empresa::where('slug', 'like', 'demo-verduleria-%')->count());

        $this->seed(VerduleriaDemoSeeder::class);

        $this->assertSame($primera, $conteo(), 'Correr el seeder dos veces no debe duplicar nada.');
    }

    public function test_configuracion_fiscal_de_cada_escenario(): void
    {
        $informal = $this->empresa('informal');
        $tipoB = $this->empresa('tipo-b');
        $ecf = $this->empresa('ecf');

        $this->assertNull($informal->rnc);
        $this->assertFalse($informal->config()->aplica_itbis);
        $this->assertTrue($informal->config()->permite_ventas_sin_comprobante);

        $this->assertTrue($tipoB->config()->aplica_itbis);
        $this->assertTrue($tipoB->config()->precio_incluye_itbis);
        $this->assertFalse($tipoB->usaEcf());

        $this->assertTrue($ecf->usaEcf());
        $this->assertFalse($ecf->config()->precio_incluye_itbis);
        $this->assertFalse($ecf->config()->permite_ventas_sin_comprobante);
    }

    public function test_el_catalogo_cubre_todas_las_formas_de_vender(): void
    {
        foreach (self::ESCENARIOS as $escenario) {
            $empresa = $this->empresa($escenario);
            $productos = Producto::where('empresa_id', $empresa->id);

            // Por peso: libra y kilogramo, con precio por peso.
            $this->assertGreaterThan(0, (clone $productos)->where('tipo_venta', TipoVenta::PESADO)->where('unidad_base', 'libra')->count(), $escenario);
            $this->assertGreaterThan(0, (clone $productos)->where('tipo_venta', TipoVenta::PESADO)->where('unidad_base', 'kilogramo')->count(), $escenario);
            $this->assertSame(0, (clone $productos)->where('tipo_venta', TipoVenta::PESADO)->whereNull('precio_por_peso')->count(), $escenario);

            // Todas las tasas que usa el negocio.
            foreach ([TasaItbis::EXENTO, TasaItbis::DIECIOCHO, TasaItbis::DIECISEIS] as $tasa) {
                $this->assertGreaterThan(0, (clone $productos)->where('tasa_itbis', $tasa)->count(), "{$escenario}: tasa {$tasa->value}");
            }

            // Servicio sin stock, producto agotado, producto bajo el mínimo y código de barras.
            $this->assertGreaterThan(0, (clone $productos)->where('tipo', TipoProducto::SERVICIO)->where('controla_stock', false)->count(), $escenario);
            $this->assertGreaterThan(0, (clone $productos)->where('controla_stock', true)->where('stock', 0)->count(), $escenario);
            $this->assertGreaterThan(0, (clone $productos)->where('stock', '>', 0)->whereColumn('stock', '<', 'stock_minimo')->count(), $escenario);
            $this->assertGreaterThan(0, (clone $productos)->whereNotNull('codigo_barra')->count(), $escenario);

            // Presentaciones: solo en productos contables, exactamente una base por producto y
            // ninguna presentación mayor más cara que comprar las unidades sueltas.
            $conPresentaciones = (clone $productos)->has('presentaciones')->with('presentaciones')->get();
            $this->assertGreaterThanOrEqual(7, $conPresentaciones->count(), $escenario);

            foreach ($conPresentaciones as $producto) {
                $this->assertSame(TipoVenta::CONTABLE, $producto->tipo_venta, $producto->nombre);
                $this->assertSame(1, $producto->presentaciones->where('es_base', true)->count(), $producto->nombre);

                foreach ($producto->presentaciones as $presentacion) {
                    $this->assertLessThanOrEqual(
                        (float) $producto->precio * (float) $presentacion->factor,
                        (float) $presentacion->precio,
                        "{$producto->nombre} - {$presentacion->nombre}",
                    );
                }
            }

            // Lista mayorista asignada al cliente con RNC y más barata que el precio normal.
            $lista = ListaPrecio::where('empresa_id', $empresa->id)->where('nombre', 'Mayorista')->firstOrFail();
            $this->assertSame(1, Cliente::where('empresa_id', $empresa->id)->where('lista_precio_id', $lista->id)->count(), $escenario);
            $this->assertGreaterThan(0, $lista->productos->count());
            $lista->productos->each(fn (Producto $p) => $this->assertLessThan((float) $p->precio, (float) $p->pivot->precio, $p->nombre));

            // Una promoción vigente de cada tipo y una vencida.
            $this->assertSame(3, Promocion::where('empresa_id', $empresa->id)->vigentes()->count(), $escenario);
            $this->assertSame(3, Promocion::where('empresa_id', $empresa->id)->vigentes()->distinct()->count('tipo'), $escenario);
            $this->assertSame(4, Promocion::where('empresa_id', $empresa->id)->count(), $escenario);
        }
    }

    public function test_los_tres_escenarios_tienen_el_mismo_catalogo(): void
    {
        $codigos = fn (string $escenario) => Producto::where('empresa_id', $this->empresa($escenario)->id)->orderBy('codigo')->pluck('codigo')->all();

        $this->assertSame($codigos('informal'), $codigos('tipo-b'));
        $this->assertSame($codigos('informal'), $codigos('ecf'));
    }

    public function test_las_ventas_demo_cubren_cada_forma_de_cobro_y_cuadran(): void
    {
        foreach (self::ESCENARIOS as $escenario) {
            $empresa = $this->empresa($escenario);
            $ventas = Venta::where('empresa_id', $empresa->id)->with('detalles')->get();

            $this->assertCount(6, $ventas, $escenario);

            foreach ([FormaPago::EFECTIVO, FormaPago::TARJETA, FormaPago::TRANSFERENCIA, FormaPago::MIXTO] as $forma) {
                $this->assertTrue($ventas->contains('forma_pago', $forma), "{$escenario}: falta venta con {$forma->value}");
            }

            foreach ($ventas as $venta) {
                // Cabecera = suma de sus líneas (el descuento global va prorrateado en ellas), y
                // el total sale del desglose.
                $this->assertSame(
                    0,
                    bccomp(bcsub((string) $venta->subtotal, (string) $venta->descuento, 2), number_format((float) $venta->detalles->sum('subtotal'), 2, '.', ''), 2),
                    "{$escenario}: subtotal venta {$venta->id}",
                );
                $this->assertSame(
                    0,
                    bccomp((string) $venta->total, bcadd(bcsub((string) $venta->subtotal, (string) $venta->descuento, 2), (string) $venta->total_itbis, 2), 2),
                    "{$escenario}: total venta {$venta->id}",
                );
            }

            // Efectivo: el vuelto es lo recibido menos el total.
            $efectivo = $ventas->first(fn (Venta $v) => $v->forma_pago === FormaPago::EFECTIVO && $v->tipo_pago === TipoPago::CONTADO);
            $this->assertSame(0, bccomp((string) $efectivo->vuelto, bcsub((string) $efectivo->monto_efectivo, (string) $efectivo->total, 2), 2), $escenario);

            // Mixto: efectivo + tarjeta = total, y lleva el descuento global.
            $mixto = $ventas->firstWhere('forma_pago', FormaPago::MIXTO);
            $this->assertSame(0, bccomp(bcadd((string) $mixto->monto_efectivo, (string) $mixto->monto_tarjeta, 2), (string) $mixto->total, 2), $escenario);
            $this->assertGreaterThan(0, (float) $mixto->descuento, $escenario);

            // Fiado: una venta a crédito con su cuenta por cobrar por el total.
            $fiado = $ventas->where('tipo_pago', TipoPago::CREDITO);
            $this->assertCount(1, $fiado, $escenario);
            $this->assertSame((string) $fiado->first()->total, (string) $fiado->first()->cuentaPorCobrar->monto_total, $escenario);

            // Hay líneas vendidas por peso con decimales y líneas con presentación (factor > 1).
            $detalles = $ventas->flatMap->detalles;
            $this->assertTrue($detalles->contains(fn (DetalleVenta $d) => fmod((float) $d->cantidad, 1.0) !== 0.0), $escenario);
            $this->assertTrue($detalles->contains(fn (DetalleVenta $d) => (float) $d->factor > 1), $escenario);
        }
    }

    public function test_itbis_y_comprobantes_segun_el_escenario(): void
    {
        $informal = Venta::where('empresa_id', $this->empresa('informal')->id)->get();
        $tipoB = Venta::where('empresa_id', $this->empresa('tipo-b')->id)->get();
        $ecf = Venta::where('empresa_id', $this->empresa('ecf')->id)->get();

        // Informal: ni NCF ni ITBIS, aunque venda productos "gravados".
        $this->assertSame(0, $informal->whereNotNull('ncf')->count());
        $this->assertSame(0.0, (float) $informal->sum('total_itbis'));

        // Tipo B: NCF físico de 11 caracteres, un B01 al mayorista y el resto B02.
        $this->assertSame(0, $tipoB->filter(fn (Venta $v) => strlen((string) $v->ncf) !== 11)->count());
        $this->assertSame(1, $tipoB->filter(fn (Venta $v) => str_starts_with($v->ncf, 'B01'))->count());
        $this->assertSame(5, $tipoB->filter(fn (Venta $v) => str_starts_with($v->ncf, 'B02'))->count());

        // e-CF: e-NCF de 13 caracteres, un E31 y el resto E32.
        $this->assertSame(0, $ecf->filter(fn (Venta $v) => strlen((string) $v->ncf) !== 13)->count());
        $this->assertSame(1, $ecf->filter(fn (Venta $v) => str_starts_with($v->ncf, 'E31'))->count());
        $this->assertSame(5, $ecf->filter(fn (Venta $v) => str_starts_with($v->ncf, 'E32'))->count());

        foreach (['tipo-b' => $tipoB, 'ecf' => $ecf] as $escenario => $ventas) {
            // La venta con exento + 18 % + 16 % desglosa las tres bases.
            $this->assertTrue(
                $ventas->contains(fn (Venta $v) => (float) $v->monto_exento > 0 && (float) $v->itbis_18 > 0 && (float) $v->itbis_16 > 0),
                "{$escenario}: falta venta con exento + 18 % + 16 %",
            );

            // Solo vegetales/víveres ⇒ sin ITBIS.
            $this->assertTrue($ventas->contains(fn (Venta $v) => (float) $v->total_itbis === 0.0 && (float) $v->monto_exento > 0), $escenario);
        }

        // Mismo carrito: con ITBIS incluido (tipo B) el cliente paga lo mismo que en el
        // informal; con ITBIS aparte (e-CF) paga más.
        $this->assertSame(0, bccomp((string) $informal->sum('total'), (string) $tipoB->sum('total'), 2));
        $this->assertGreaterThan((float) $tipoB->sum('total'), (float) $ecf->sum('total'));
    }

    public function test_el_stock_baja_en_unidad_base(): void
    {
        foreach (self::ESCENARIOS as $escenario) {
            $empresa = $this->empresa($escenario);

            // Plátano: 1.200 − (1 docena + 3 unidades) − 2 cientos − 24 unidades = 961.
            $this->assertSame('961.000', (string) $this->producto($empresa, 'VIV-005')->stock, $escenario);
            // Papa al peso: 300 − 5 lb − 50 lb = 245; el saco es otro producto: 20 − 2 = 18.
            $this->assertSame('245.000', (string) $this->producto($empresa, 'VIV-001')->stock, $escenario);
            $this->assertSame('18.000', (string) $this->producto($empresa, 'MAY-001')->stock, $escenario);
            // Tomate con decimales: 150 − 2,5 = 147,5. Jengibre en kg: 25 − 0,25.
            $this->assertSame('147.500', (string) $this->producto($empresa, 'VER-001')->stock, $escenario);
            $this->assertSame('24.750', (string) $this->producto($empresa, 'HIE-001')->stock, $escenario);
            // El servicio no lleva stock.
            $this->assertSame('0.000', (string) $this->producto($empresa, 'SRV-001')->stock, $escenario);
        }
    }

    public function test_promociones_y_lista_mayorista_se_aplican_en_las_ventas(): void
    {
        $empresa = $this->empresa('informal');
        $detalle = fn (string $codigo) => DetalleVenta::whereHas('venta', fn ($q) => $q->where('empresa_id', $empresa->id))
            ->where('producto_id', $this->producto($empresa, $codigo)->id);

        // Tomate 15 %: 2,5 lb × 35 = 87,50 → descuento 13,13 (redondeo HALF_UP de 13,125).
        $this->assertSame('13.13', (string) $detalle('VER-001')->value('descuento'));
        // Esa venta lleva además el descuento global del 10 %, prorrateado sobre lo que queda:
        // Piña RD$20 menos × 2 = 40, más 10 % de (200 − 40) = 56.
        $this->assertSame('56.00', (string) $detalle('FRU-003')->value('descuento'));
        // Coco 2×1 (lleva 2, paga 1) = 50, más 10 % de (100 − 50) = 55.
        $this->assertSame('55.00', (string) $detalle('FRU-004')->value('descuento'));
        // La promoción vencida del aguacate no se aplica.
        $this->assertSame('0.00', (string) $detalle('FRU-002')->value('descuento'));

        $alMayorista = fn (string $codigo) => $detalle($codigo)->whereHas('venta.cliente', fn ($q) => $q->whereNotNull('lista_precio_id'));
        $alPublico = fn (string $codigo) => $detalle($codigo)->whereDoesntHave('venta.cliente', fn ($q) => $q->whereNotNull('lista_precio_id'));

        // Lista mayorista (cliente con RNC): aplica a productos por unidad en su unidad base.
        $this->assertSame('2600.00', (string) $alMayorista('MAY-001')->value('precio_unitario')); // normal 2.800
        $this->assertSame('1850.00', (string) $alMayorista('MAY-002')->value('precio_unitario')); // normal 2.000
        $this->assertSame('14.00', (string) $alMayorista('VIV-005')->where('factor', 1)->value('precio_unitario')); // normal 18
        $this->assertSame('50.00', (string) $alMayorista('FRU-002')->value('precio_unitario'));  // normal 60
        $this->assertSame('60.00', (string) $alPublico('FRU-002')->value('precio_unitario'));

        // Igual que en el POS, no aplica a productos por peso ni a presentaciones: la papa por
        // libra, el ciento de plátano, el cartón de huevos y el saco de naranja van a precio normal.
        $this->assertSame('32.00', (string) $alMayorista('VIV-001')->value('precio_unitario'));
        $this->assertSame('1500.00', (string) $alMayorista('VIV-005')->where('factor', 100)->value('precio_unitario'));
        $this->assertSame('220.00', (string) $alMayorista('GRA-003')->where('factor', 30)->value('precio_unitario'));
        $this->assertSame('750.00', (string) $alMayorista('FRU-006')->where('factor', 100)->value('precio_unitario'));
        $this->assertSame('200.00', (string) $alPublico('VIV-005')->where('factor', 12)->value('precio_unitario'));
    }

    public function test_el_producto_agotado_no_se_puede_vender(): void
    {
        $empresa = $this->empresa('informal');

        $this->expectException(\App\Exceptions\StockInsuficienteException::class);

        app(VentaService::class)->registrar([
            'sin_comprobante' => true,
            'forma_pago' => FormaPago::EFECTIVO,
            'lineas' => [['producto_id' => $this->producto($empresa, 'FRU-005')->id, 'cantidad' => 1]],
        ], $empresa);
    }
}
