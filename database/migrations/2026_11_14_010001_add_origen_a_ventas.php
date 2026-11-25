<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ventas.origen: desde qué pantalla se cobró (App\Enums\OrigenVenta).
 *
 * Reconstrucción de lo ya vendido: la venta de una cotización convertida queda como "cotizacion";
 * todo lo demás como "pos". Las notas de crédito/débito heredan el origen de la venta que modifican.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->string('origen', 20)->default('pos')->after('caja_id');
            $table->index(['empresa_id', 'origen', 'fecha'], 'idx_ventas_empresa_origen_fecha');
        });

        if (Schema::hasTable('cotizaciones') && Schema::hasColumn('cotizaciones', 'venta_id')) {
            DB::statement("UPDATE ventas SET origen = 'cotizacion' WHERE id IN (SELECT venta_id FROM cotizaciones WHERE venta_id IS NOT NULL)");
        }

        DB::statement(<<<'SQL'
            UPDATE ventas n SET origen = o.origen
            FROM ventas o WHERE o.id = n.venta_modificada_id AND n.origen <> o.origen
        SQL);
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropIndex('idx_ventas_empresa_origen_fecha');
            $table->dropColumn('origen');
        });
    }
};
