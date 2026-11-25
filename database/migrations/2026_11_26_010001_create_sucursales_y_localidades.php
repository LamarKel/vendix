<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sucursales → localidades → stock por localidad.
 *
 * - Cada localidad pertenece a UNA sucursal y tiene un tipo: venta (de ahí descuentan las cajas),
 *   recepción (ahí entran las compras) o almacenaje. Una de venta y una de recepción por sucursal.
 * - stock_localidades es el número real; productos.stock queda como el total de la empresa
 *   (suma de sus localidades) para que reportes y pantallas que leen el total sigan igual.
 * - Las empresas existentes reciben una sucursal "Principal" con Tienda (venta), Recibo
 *   (recepción) y Almacén, y TODO su stock actual pasa a la Tienda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sucursales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('nombre', 80);
            $table->string('codigo', 20)->nullable();
            $table->string('direccion', 255)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->boolean('es_principal')->default(false);
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->unique(['empresa_id', 'nombre']);
            $table->index(['empresa_id', 'activa']);
        });

        Schema::create('localidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->restrictOnDelete();
            $table->string('nombre', 80);
            $table->string('tipo', 20);
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->unique(['sucursal_id', 'nombre']);
            $table->index(['empresa_id', 'sucursal_id']);
        });

        // Una sola localidad de venta y una de recepción ACTIVA por sucursal.
        DB::statement("CREATE UNIQUE INDEX localidades_una_venta_por_sucursal ON localidades (sucursal_id) WHERE tipo = 'venta' AND activa");
        DB::statement("CREATE UNIQUE INDEX localidades_una_recepcion_por_sucursal ON localidades (sucursal_id) WHERE tipo = 'recepcion' AND activa");

        Schema::create('stock_localidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('localidad_id')->constrained('localidades')->restrictOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->decimal('cantidad', 14, 3)->default(0);
            $table->timestamps();

            $table->unique(['localidad_id', 'producto_id']);
            $table->index(['producto_id', 'localidad_id']);
            $table->index(['empresa_id', 'producto_id']);
        });

        Schema::table('users', fn (Blueprint $t) => $t->foreignId('sucursal_id')->nullable()->after('empresa_id')->constrained('sucursales')->nullOnDelete());
        Schema::table('cajas', fn (Blueprint $t) => $t->foreignId('sucursal_id')->nullable()->after('empresa_id')->constrained('sucursales')->nullOnDelete());
        Schema::table('ventas', function (Blueprint $t) {
            $t->foreignId('sucursal_id')->nullable()->after('empresa_id')->constrained('sucursales')->nullOnDelete();
            $t->index(['empresa_id', 'sucursal_id', 'fecha']);
        });
        Schema::table('compras', function (Blueprint $t) {
            $t->foreignId('sucursal_id')->nullable()->after('empresa_id')->constrained('sucursales')->nullOnDelete();
            $t->index(['empresa_id', 'sucursal_id', 'fecha']);
        });
        Schema::table('movimientos_inventario', function (Blueprint $t) {
            $t->foreignId('localidad_id')->nullable()->after('producto_id')->constrained('localidades')->nullOnDelete();
            $t->decimal('stock_localidad_anterior', 14, 3)->nullable()->after('stock_nuevo');
            $t->decimal('stock_localidad_nuevo', 14, 3)->nullable()->after('stock_localidad_anterior');
            $t->index(['localidad_id', 'producto_id', 'created_at']);
        });

        Schema::table('empresa_configuracion', function (Blueprint $t) {
            // false = las compras entran directo a la localidad de venta (como hasta ahora); true =
            // entran a la de recepción y alguien las ubica. Las empresas nuevas se crean con true.
            $t->boolean('compras_a_recepcion')->default(false);
            $t->boolean('requisicion_sucursal_requiere_aprobacion')->default(false);
            $t->unsignedSmallInteger('dias_alerta_recepcion')->default(3);
        });

        // Estructura inicial para las empresas que ya existen.
        $ahora = now();
        foreach (DB::table('empresas')->pluck('id') as $empresaId) {
            $sucursalId = DB::table('sucursales')->insertGetId([
                'empresa_id' => $empresaId, 'nombre' => 'Principal', 'es_principal' => true, 'activa' => true,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);

            $ids = [];
            foreach (['venta' => 'Tienda', 'recepcion' => 'Recibo', 'almacenaje' => 'Almacén'] as $tipo => $nombre) {
                $ids[$tipo] = DB::table('localidades')->insertGetId([
                    'empresa_id' => $empresaId, 'sucursal_id' => $sucursalId, 'nombre' => $nombre, 'tipo' => $tipo,
                    'activa' => true, 'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
            }

            DB::statement(
                'INSERT INTO stock_localidades (empresa_id, localidad_id, producto_id, cantidad, created_at, updated_at)
                 SELECT empresa_id, ?, id, stock, ?, ? FROM productos WHERE empresa_id = ? AND controla_stock AND stock <> 0',
                [$ids['venta'], $ahora, $ahora, $empresaId]
            );

            DB::table('users')->where('empresa_id', $empresaId)->update(['sucursal_id' => $sucursalId]);
            DB::table('cajas')->where('empresa_id', $empresaId)->update(['sucursal_id' => $sucursalId]);
            DB::table('ventas')->where('empresa_id', $empresaId)->update(['sucursal_id' => $sucursalId]);
            DB::table('compras')->where('empresa_id', $empresaId)->update(['sucursal_id' => $sucursalId]);
        }
    }

    public function down(): void
    {
        Schema::table('empresa_configuracion', fn (Blueprint $t) => $t->dropColumn(['compras_a_recepcion', 'requisicion_sucursal_requiere_aprobacion', 'dias_alerta_recepcion']));
        Schema::table('movimientos_inventario', function (Blueprint $t) {
            $t->dropConstrainedForeignId('localidad_id');
            $t->dropColumn(['stock_localidad_anterior', 'stock_localidad_nuevo']);
        });
        foreach (['compras', 'ventas', 'cajas', 'users'] as $tabla) {
            Schema::table($tabla, fn (Blueprint $t) => $t->dropConstrainedForeignId('sucursal_id'));
        }
        Schema::dropIfExists('stock_localidades');
        Schema::dropIfExists('localidades');
        Schema::dropIfExists('sucursales');
    }
};
