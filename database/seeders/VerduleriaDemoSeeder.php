<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\FormaPago;
use App\Enums\Modulo;
use App\Enums\PerfilEmpresa;
use App\Enums\TasaItbis;
use App\Enums\TipoPago;
use App\Enums\TipoProducto;
use App\Enums\TipoPromocion;
use App\Enums\TipoVenta;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Descuento;
use App\Models\Empresa;
use App\Models\ListaPrecio;
use App\Models\Producto;
use App\Models\ProductoPresentacion;
use App\Models\Promocion;
use App\Models\User;
use App\Models\Venta;
use App\Services\VentaService;
use App\Support\ComprobanteVenta;
use Database\Seeders\Concerns\SiembraEmpresaDemo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Verdulería / frutería / puesto de víveres demo. Perfil RETAIL: no necesita módulo propio,
 * usa el POS normal con todas las formas de vender que tiene un negocio de este tipo:
 *
 *  - Por peso en libras (tomate, cebolla, papa...) y en kilogramos (jengibre).
 *  - Por unidad (aguacate, piña, lechuga).
 *  - Por unidad con presentaciones: docena, ciento, cartón, mazo, ristra, saco, caja.
 *  - Sacos cerrados al por mayor como producto aparte (los pesados no llevan presentaciones).
 *  - Exentos de ITBIS (víveres, frutas y vegetales frescos), gravados al 18 % (fundas,
 *    refrescos, sazón, delivery) y al 16 % (azúcar, aceite).
 *  - Servicio sin stock (delivery), producto bajo el mínimo, producto agotado, código de barras.
 *  - Lista de precio "Mayorista" asignada a un cliente (colmado que revende).
 *  - Promociones: % vigente, monto fijo vigente, 2×1 vigente y una vencida.
 *  - Descuentos globales de 5 % y 10 %.
 *
 * Tres escenarios fiscales (ver SiembraEmpresaDemo):
 *  - Puesto Frutos del Campo   (informal) — sin ITBIS, sin comprobante.
 *  - Verduras Don Ramón        (tipo B)   — ITBIS incluido, B01/B02.
 *  - Agromercado La Cosecha    (e-CF)     — ITBIS aparte, E31/E32.
 *
 * Ventas demo (solo la primera vez): contado efectivo con vuelto, tarjeta, transferencia,
 * pago mixto, fiado (crédito → cuenta por cobrar), mayorista con lista de precio, descuento
 * global y crédito fiscal donde el escenario lo permite.
 *
 *   sail artisan db:seed --class=VerduleriaDemoSeeder
 */
class VerduleriaDemoSeeder extends Seeder
{
    use SiembraEmpresaDemo;

    private const MODULOS_EXTRA = [
        Modulo::MAESTROS_DESCUENTOS,
        Modulo::MAESTROS_PROMOCIONES,
        Modulo::MAESTROS_LISTAS_PRECIO,
        Modulo::VENTAS_ARQUEO_CAJA,
        Modulo::INVENTARIO_KARDEX,
        Modulo::ECF_SECUENCIAS,
        Modulo::CUENTAS_POR_COBRAR,
    ];

    public function run(): void
    {
        $this->abortarEnProduccion();

        $this->command->info('Verdulería demo:');

        $this->sembrarEscenario(self::ESCENARIO_INFORMAL, [
            'razon_social' => 'Puesto Frutos del Campo',
            'nombre_comercial' => 'Frutos del Campo',
            'rnc' => null,
            'slug' => 'demo-verduleria-informal',
            'email_admin' => 'admin@verd-informal.test',
        ], 'verd-informal', '0000401');

        $this->sembrarEscenario(self::ESCENARIO_TIPO_B, [
            'razon_social' => 'Verduras Don Ramón SRL',
            'nombre_comercial' => 'Verduras Don Ramón',
            'rnc' => '131900401',
            'slug' => 'demo-verduleria-tipo-b',
            'email_admin' => 'admin@verd-tipob.test',
        ], 'verd-tipob', '0000402');

        $this->sembrarEscenario(self::ESCENARIO_ECF, [
            'razon_social' => 'Agromercado La Cosecha SRL',
            'nombre_comercial' => 'Agromercado La Cosecha',
            'rnc' => '131900402',
            'slug' => 'demo-verduleria-ecf',
            'email_admin' => 'admin@verd-ecf.test',
        ], 'verd-ecf', '0000403');
    }

    /** @param  array<string, ?string>  $datos */
    private function sembrarEscenario(string $escenario, array $datos, string $dominio, string $sufijoRnc): void
    {
        [$empresa, $admin] = $this->crearEmpresaDemo(PerfilEmpresa::RETAIL, $escenario, $datos, self::MODULOS_EXTRA);

        $cajero = $this->crearUsuarioDemo($empresa, 'María Cajera', "caja@{$dominio}.test", 'Vendedor');
        $this->crearUsuarioDemo($empresa, 'José Almacén', "almacen@{$dominio}.test", 'Almacenista');

        $categorias = $this->sembrarCategorias($empresa);
        $productos = $this->sembrarProductos($empresa, $categorias);
        $clientes = $this->sembrarClientesDemo($empresa, $sufijoRnc);

        $this->sembrarListaMayorista($empresa, $productos, $clientes['rnc']);
        $this->sembrarPromociones($empresa, $productos);

        foreach ([5, 10] as $porcentaje) {
            Descuento::firstOrCreate(
                ['empresa_id' => $empresa->id, 'nombre' => "Descuento {$porcentaje}%"],
                ['porcentaje' => $porcentaje, 'activo' => true],
            );
        }

        if (! Venta::where('empresa_id', $empresa->id)->exists()) {
            $this->sembrarVentas($empresa, $escenario, $productos, $clientes, $cajero);
        }

        $this->resumenEmpresa($empresa, $escenario, [$admin->email, "caja@{$dominio}.test", "almacen@{$dominio}.test"]);
    }

    /** @return Collection<string, Categoria> */
    private function sembrarCategorias(Empresa $empresa): Collection
    {
        $categorias = [
            'Vegetales' => 'Tomate, cebolla, ají, zanahoria, lechuga',
            'Víveres' => 'Plátano, yuca, papa, batata, auyama, guineo verde',
            'Frutas' => 'Aguacate, piña, lechosa, naranja, limón',
            'Hierbas y sazones' => 'Cilantro, verdura, ajo, jengibre, orégano',
            'Huevos y granos' => 'Huevos, habichuelas, guandules',
            'Al por mayor' => 'Sacos y cajas cerradas',
            'Abarrotes' => 'Azúcar, aceite, sazón, refrescos, fundas',
            'Servicios' => 'Delivery',
        ];

        return collect($categorias)->mapWithKeys(fn (string $descripcion, string $nombre) => [$nombre => Categoria::firstOrCreate(
            ['empresa_id' => $empresa->id, 'nombre' => $nombre],
            ['descripcion' => $descripcion, 'activo' => true],
        )]);
    }

    /**
     * @param  Collection<string, Categoria>  $categorias
     * @return Collection<string, Producto> indexada por código
     */
    private function sembrarProductos(Empresa $empresa, Collection $categorias): Collection
    {
        $productos = collect();

        $crear = function (string $codigo, string $categoria, array $datos) use ($empresa, $categorias, $productos): Producto {
            $producto = Producto::firstOrCreate(
                ['empresa_id' => $empresa->id, 'codigo' => $codigo],
                $datos + [
                    'categoria_id' => $categorias[$categoria]->id,
                    'tipo' => TipoProducto::PRODUCTO,
                    'tipo_venta' => TipoVenta::CONTABLE,
                    'unidad_base' => 'unidad',
                    // Vegetales, víveres y frutas frescas están exentos de ITBIS.
                    'tasa_itbis' => TasaItbis::EXENTO,
                    'controla_stock' => true,
                    'stock_minimo' => 0,
                    'activo' => true,
                ],
            );

            return $productos[$codigo] = $producto;
        };

        // [código, categoría, nombre, precio/unidad de peso, costo, stock, mínimo, unidad]
        $pesados = [
            ['VER-001', 'Vegetales', 'Tomate de ensalada', 35.00, 20.00, 150, 25, 'libra'],
            ['VER-002', 'Vegetales', 'Cebolla roja', 45.00, 28.00, 200, 30, 'libra'],
            ['VER-003', 'Vegetales', 'Ají cubanela', 40.00, 24.00, 80, 15, 'libra'],
            ['VER-004', 'Vegetales', 'Zanahoria', 30.00, 17.00, 90, 15, 'libra'],
            ['VER-005', 'Vegetales', 'Repollo', 20.00, 11.00, 120, 20, 'libra'],
            ['VIV-001', 'Víveres', 'Papa', 32.00, 20.00, 300, 50, 'libra'],
            ['VIV-002', 'Víveres', 'Yuca', 22.00, 12.00, 250, 40, 'libra'],
            ['VIV-003', 'Víveres', 'Batata', 25.00, 14.00, 150, 25, 'libra'],
            ['VIV-004', 'Víveres', 'Auyama', 20.00, 10.00, 180, 30, 'libra'],
            ['FRU-001', 'Frutas', 'Lechosa', 25.00, 13.00, 100, 15, 'libra'],
            ['GRA-001', 'Huevos y granos', 'Habichuelas rojas', 65.00, 45.00, 200, 30, 'libra'],
            ['GRA-002', 'Huevos y granos', 'Guandules verdes', 90.00, 60.00, 8, 10, 'libra'], // bajo el mínimo
            // Único producto en kilogramos: prueba la otra unidad de peso.
            ['HIE-001', 'Hierbas y sazones', 'Jengibre', 180.00, 110.00, 25, 5, 'kilogramo'],
        ];

        foreach ($pesados as [$codigo, $categoria, $nombre, $precio, $costo, $stock, $minimo, $unidad]) {
            $crear($codigo, $categoria, [
                'nombre' => $nombre,
                'tipo_venta' => TipoVenta::PESADO,
                'unidad_base' => $unidad,
                'precio' => $precio, 'precio_por_peso' => $precio, 'costo' => $costo,
                'stock' => $stock, 'stock_minimo' => $minimo,
            ]);
        }

        // Azúcar: pesado y gravado al 16 %.
        $crear('ABA-001', 'Abarrotes', [
            'nombre' => 'Azúcar crema',
            'tipo_venta' => TipoVenta::PESADO,
            'unidad_base' => 'libra',
            'tasa_itbis' => TasaItbis::DIECISEIS,
            'precio' => 38.00, 'precio_por_peso' => 38.00, 'costo' => 30.00,
            'stock' => 250, 'stock_minimo' => 50,
        ]);

        // Por unidad, sin presentaciones.
        $unidades = [
            ['FRU-002', 'Frutas', 'Aguacate', 60.00, 35.00, 80, 15],
            ['FRU-003', 'Frutas', 'Piña', 100.00, 60.00, 40, 8],
            ['FRU-004', 'Frutas', 'Coco seco', 50.00, 28.00, 30, 5],
            ['VER-006', 'Vegetales', 'Lechuga repollada', 55.00, 30.00, 35, 8],
            ['FRU-005', 'Frutas', 'Sandía (melón de agua)', 250.00, 150.00, 0, 3], // agotado: la venta debe bloquearse
        ];

        foreach ($unidades as [$codigo, $categoria, $nombre, $precio, $costo, $stock, $minimo]) {
            $crear($codigo, $categoria, [
                'nombre' => $nombre,
                'precio' => $precio, 'costo' => $costo,
                'stock' => $stock, 'stock_minimo' => $minimo,
            ]);
        }

        // Por unidad con presentaciones [nombre, factor, precio, es base, código de barras].
        $conPresentaciones = [
            ['VIV-005', 'Víveres', 'Plátano verde', 18.00, 11.00, 1200, 200, [
                ['Unidad', 1, 18.00, true, 'VIV005U'],
                ['Docena', 12, 200.00, false, 'VIV005D'],
                ['Ciento', 100, 1500.00, false, 'VIV005C'],
            ]],
            ['VIV-006', 'Víveres', 'Guineo verde', 6.00, 3.50, 1500, 300, [
                ['Unidad', 1, 6.00, true, 'VIV006U'],
                ['Mano (10)', 10, 50.00, false, 'VIV006M'],
                ['Racimo (80)', 80, 360.00, false, 'VIV006R'],
            ]],
            ['FRU-006', 'Frutas', 'Naranja dulce', 10.00, 5.00, 2000, 300, [
                ['Unidad', 1, 10.00, true, 'FRU006U'],
                ['Docena', 12, 100.00, false, 'FRU006D'],
                ['Saco (100)', 100, 750.00, false, 'FRU006S'],
            ]],
            ['FRU-007', 'Frutas', 'Limón agrio', 5.00, 2.50, 1000, 150, [
                ['Unidad', 1, 5.00, true, 'FRU007U'],
                ['Funda (25)', 25, 100.00, false, 'FRU007F'],
            ]],
            ['GRA-003', 'Huevos y granos', 'Huevo', 8.00, 6.00, 1800, 300, [
                ['Unidad', 1, 8.00, true, 'GRA003U'],
                ['Medio cartón (15)', 15, 115.00, false, 'GRA003M'],
                ['Cartón (30)', 30, 220.00, false, 'GRA003C'],
            ]],
            ['HIE-002', 'Hierbas y sazones', 'Cilantro y verdura', 15.00, 7.00, 120, 20, [
                ['Mazo', 1, 15.00, true, 'HIE002M'],
                ['Paquete (6 mazos)', 6, 80.00, false, 'HIE002P'],
            ]],
            ['HIE-003', 'Hierbas y sazones', 'Ajo', 20.00, 12.00, 400, 60, [
                ['Cabeza', 1, 20.00, true, 'HIE003C'],
                ['Ristra (10)', 10, 180.00, false, 'HIE003R'],
                ['Caja (100)', 100, 1600.00, false, 'HIE003X'],
            ]],
        ];

        foreach ($conPresentaciones as [$codigo, $categoria, $nombre, $precio, $costo, $stock, $minimo, $presentaciones]) {
            $producto = $crear($codigo, $categoria, [
                'nombre' => $nombre,
                'precio' => $precio, 'costo' => $costo,
                'stock' => $stock, 'stock_minimo' => $minimo,
            ]);

            $this->presentaciones($empresa, $producto, $presentaciones);
        }

        // Al por mayor: los productos pesados no llevan presentaciones, así que el saco cerrado
        // es un producto aparte que se vende por unidad con su propio stock.
        $mayor = [
            ['MAY-001', 'Saco de papa (100 lb)', 2800.00, 2000.00, 20, 4],
            ['MAY-002', 'Saco de cebolla roja (50 lb)', 2000.00, 1400.00, 15, 3],
            ['MAY-003', 'Caja de tomate (40 lb)', 1200.00, 800.00, 12, 3],
            ['MAY-004', 'Saco de yuca (100 lb)', 1900.00, 1200.00, 2, 3], // bajo el mínimo
        ];

        foreach ($mayor as [$codigo, $nombre, $precio, $costo, $stock, $minimo]) {
            $crear($codigo, 'Al por mayor', [
                'nombre' => $nombre,
                'precio' => $precio, 'costo' => $costo,
                'stock' => $stock, 'stock_minimo' => $minimo,
            ]);
        }

        // Abarrotes gravados, con código de barras para el escáner.
        $abarrotes = [
            ['ABA-002', 'Aceite de soya 16 oz', TasaItbis::DIECISEIS, 95.00, 75.00, 60, 12, '7460000004021'],
            ['ABA-003', 'Sazón líquido 15 oz', TasaItbis::DIECIOCHO, 85.00, 60.00, 48, 10, '7460000004038'],
            ['ABA-004', 'Refresco 20 oz', TasaItbis::DIECIOCHO, 40.00, 28.00, 96, 24, '7460000004045'],
            ['ABA-005', 'Fundas plásticas (paquete 100)', TasaItbis::DIECIOCHO, 75.00, 50.00, 40, 10, '7460000004052'],
        ];

        foreach ($abarrotes as [$codigo, $nombre, $tasa, $precio, $costo, $stock, $minimo, $codigoBarra]) {
            $crear($codigo, 'Abarrotes', [
                'nombre' => $nombre,
                'codigo_barra' => $codigoBarra,
                'tasa_itbis' => $tasa,
                'precio' => $precio, 'costo' => $costo,
                'stock' => $stock, 'stock_minimo' => $minimo,
            ]);
        }

        // Servicio sin stock.
        $crear('SRV-001', 'Servicios', [
            'nombre' => 'Delivery a domicilio',
            'tipo' => TipoProducto::SERVICIO,
            'tasa_itbis' => TasaItbis::DIECIOCHO,
            'controla_stock' => false,
            'precio' => 100.00, 'costo' => 0,
            'stock' => 0,
        ]);

        return $productos;
    }

    /** @param  array<int, array{0: string, 1: int, 2: float, 3: bool, 4: string}>  $presentaciones */
    private function presentaciones(Empresa $empresa, Producto $producto, array $presentaciones): void
    {
        foreach ($presentaciones as [$nombre, $factor, $precio, $esBase, $codigoBarra]) {
            ProductoPresentacion::firstOrCreate(
                ['empresa_id' => $empresa->id, 'producto_id' => $producto->id, 'nombre' => $nombre],
                [
                    'factor' => $factor,
                    'precio' => $precio,
                    'es_base' => $esBase,
                    // Código de barras distinto por empresa: el UNIQUE es (empresa_id, codigo_barra).
                    'codigo_barra' => $codigoBarra.$empresa->id,
                    'activa' => true,
                ],
            );
        }
    }

    /**
     * Lista "Mayorista" asignada al cliente con RNC (un colmado que revende): al elegirlo en
     * el POS, estos productos salen al precio de lista en vez del precio normal. Como en el POS,
     * la lista aplica solo a productos por unidad en su unidad base: los productos por peso y
     * las presentaciones (docena, ciento, cartón) conservan su propio precio.
     *
     * @param  Collection<string, Producto>  $productos
     */
    private function sembrarListaMayorista(Empresa $empresa, Collection $productos, Cliente $mayorista): void
    {
        $lista = ListaPrecio::firstOrCreate(
            ['empresa_id' => $empresa->id, 'nombre' => 'Mayorista'],
            ['descripcion' => 'Colmados, restaurantes y revendedores', 'activa' => true],
        );

        $precios = [
            'VIV-005' => 14.00,   // plátano, por unidad (18)
            'GRA-003' => 7.00,    // huevo, por unidad (8)
            'FRU-006' => 9.00,    // naranja, por unidad (10)
            'FRU-002' => 50.00,   // aguacate (60)
            'MAY-001' => 2600.00, // saco de papa (2,800)
            'MAY-002' => 1850.00, // saco de cebolla (2,000)
        ];

        $lista->productos()->syncWithoutDetaching(
            collect($precios)->mapWithKeys(fn (float $precio, string $codigo) => [$productos[$codigo]->id => ['precio' => $precio]])->all(),
        );

        if ($mayorista->lista_precio_id === null) {
            $mayorista->update(['lista_precio_id' => $lista->id]);
        }
    }

    /**
     * Una promoción de cada tipo, vigente, más una vencida que no debe aplicarse.
     *
     * @param  Collection<string, Producto>  $productos
     */
    private function sembrarPromociones(Empresa $empresa, Collection $productos): void
    {
        $promos = [
            ['Tomate en oferta 15%', TipoPromocion::PORCENTAJE, 15, 'VER-001', true],
            ['Piña RD$20 menos', TipoPromocion::MONTO_FIJO, 20, 'FRU-003', true],
            ['2×1 en coco seco', TipoPromocion::DOS_POR_UNO, null, 'FRU-004', true],
            ['Aguacate 25% (vencida)', TipoPromocion::PORCENTAJE, 25, 'FRU-002', false],
        ];

        foreach ($promos as [$nombre, $tipo, $valor, $codigo, $vigente]) {
            $promo = Promocion::firstOrCreate(
                ['empresa_id' => $empresa->id, 'nombre' => $nombre],
                [
                    'tipo' => $tipo,
                    'valor' => $valor,
                    'fecha_inicio' => $vigente ? now()->subDays(3)->toDateString() : now()->subDays(30)->toDateString(),
                    'fecha_fin' => $vigente ? now()->addMonth()->toDateString() : now()->subDays(10)->toDateString(),
                    'activa' => true,
                ],
            );

            $promo->productos()->syncWithoutDetaching([$productos[$codigo]->id]);
        }
    }

    /**
     * Una venta por cada forma de cobrar y de vender.
     *
     * @param  Collection<string, Producto>  $productos
     * @param  array{rnc: Cliente, cedula: Cliente, sin_documento: Cliente}  $clientes
     */
    private function sembrarVentas(Empresa $empresa, string $escenario, Collection $productos, array $clientes, User $cajero): void
    {
        $servicio = app(VentaService::class);

        [$consumo, $credito] = match ($escenario) {
            self::ESCENARIO_INFORMAL => [ComprobanteVenta::SIN_COMPROBANTE, null],
            self::ESCENARIO_TIPO_B => ['B02', 'B01'],
            self::ESCENARIO_ECF => ['32', '31'],
        };

        // Sin precio_unitario: VentaService pone el de la lista del cliente, la presentación o
        // el producto, y sobre ese precio aplica las promociones.
        $linea = function (string $codigo, float $cantidad, ?string $presentacion = null) use ($productos): array {
            $producto = $productos[$codigo];

            return [
                'producto_id' => $producto->id,
                'presentacion_id' => $presentacion === null ? null : $producto->presentaciones()->where('nombre', $presentacion)->firstOrFail()->id,
                'cantidad' => $cantidad,
            ];
        };

        $vender = function (array $lineas, array $datos, string $comprobante) use ($servicio, $empresa, $cajero): Venta {
            $datos += ['lineas' => $lineas, 'user_id' => $cajero->id] + ComprobanteVenta::aDatosVenta($comprobante, $empresa);

            if (isset($datos['monto_efectivo']) && ($datos['forma_pago'] ?? null) === FormaPago::EFECTIVO) {
                $vuelto = bcsub(number_format((float) $datos['monto_efectivo'], 2, '.', ''), $servicio->previsualizar($datos, $empresa)['total'], 2);
                $datos['vuelto'] = bccomp($vuelto, '0', 2) > 0 ? $vuelto : null;
            }

            return $servicio->registrar($datos, $empresa);
        };

        // 1. Compra del día al peso (libras con decimales + kilogramo), efectivo con vuelto,
        //    al portador. El tomate lleva la promoción del 15 %.
        $vender([
            $linea('VER-001', 2.5),
            $linea('VER-002', 1.25),
            $linea('VIV-001', 5),
            $linea('HIE-001', 0.25),
            $linea('HIE-002', 2),
        ], ['forma_pago' => FormaPago::EFECTIVO, 'monto_efectivo' => 1000], $consumo);

        // 2. Presentaciones (docena, cartón, ristra) + unidad suelta del mismo producto, tarjeta.
        $vender([
            $linea('VIV-005', 1, 'Docena'),
            $linea('VIV-005', 3, 'Unidad'),
            $linea('GRA-003', 1, 'Cartón (30)'),
            $linea('HIE-003', 1, 'Ristra (10)'),
        ], [
            'forma_pago' => FormaPago::TARJETA,
            'cliente_id' => $consumo === ComprobanteVenta::SIN_COMPROBANTE ? null : $clientes['cedula']->id,
        ], $consumo);

        // 3. Exento + 18 % + 16 % + servicio en la misma factura, transferencia.
        $vender([
            $linea('FRU-002', 4),
            $linea('ABA-001', 5),
            $linea('ABA-002', 2),
            $linea('ABA-004', 6),
            $linea('SRV-001', 1),
        ], ['forma_pago' => FormaPago::TRANSFERENCIA], $consumo);

        // 4. Promociones de monto fijo y 2×1 + descuento global del 10 %, pago mixto.
        $lineasMixto = [$linea('FRU-003', 2), $linea('FRU-004', 2), $linea('FRU-006', 2, 'Docena')];
        $descuento = Descuento::where('empresa_id', $empresa->id)->where('nombre', 'Descuento 10%')->value('id');
        $total = $servicio->previsualizar(['lineas' => $lineasMixto, 'descuento_id' => $descuento], $empresa)['total'];

        $vender($lineasMixto, [
            'forma_pago' => FormaPago::MIXTO,
            'descuento_id' => $descuento,
            'monto_efectivo' => '200.00',
            'monto_tarjeta' => bcsub($total, '200.00', 2),
        ], $consumo);

        // 5. Fiado: venta a crédito a un cliente conocido → abre cuenta por cobrar.
        $vender([
            $linea('VIV-002', 10),
            $linea('VIV-006', 2, 'Mano (10)'),
            $linea('GRA-001', 3),
        ], [
            'tipo_pago' => TipoPago::CREDITO,
            'cliente_id' => $clientes['sin_documento']->id,
        ], $consumo);

        // 6. Mayorista: sacos cerrados y productos por unidad a precio de la lista "Mayorista" del
        //    cliente con RNC. Lo que va por peso o en presentación (ciento, cartón, saco) sale a
        //    su precio normal, igual que en el POS.
        //    Con crédito fiscal donde el escenario lo tiene; en el informal, sin comprobante.
        $vender([
            $linea('MAY-001', 2),
            $linea('MAY-002', 1),
            $linea('VIV-005', 24, 'Unidad'),
            $linea('FRU-002', 10),
            $linea('VIV-001', 50),
            $linea('VER-002', 20),
            $linea('VIV-005', 2, 'Ciento'),
            $linea('GRA-003', 5, 'Cartón (30)'),
            $linea('FRU-006', 1, 'Saco (100)'),
            $linea('ABA-005', 3),
        ], [
            'forma_pago' => FormaPago::TRANSFERENCIA,
            'cliente_id' => $clientes['rnc']->id,
        ], $credito ?? $consumo);
    }
}
