<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Control antirrobo: quién anuló cada venta y umbrales por empresa para las alertas. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $t) {
            $t->foreignId('anulada_por')->nullable()->after('anulada_en')->constrained('users')->nullOnDelete();
        });

        Schema::table('empresa_configuracion', function (Blueprint $t) {
            $t->unsignedSmallInteger('antirrobo_umbral_anulaciones')->default(3);
            $t->decimal('antirrobo_umbral_descuentos', 14, 2)->default(1000);
            $t->unsignedSmallInteger('antirrobo_umbral_devoluciones')->default(3);
            $t->decimal('antirrobo_umbral_ajustes', 14, 2)->default(2000);
        });
    }

    public function down(): void
    {
        Schema::table('ventas', fn (Blueprint $t) => $t->dropConstrainedForeignId('anulada_por'));
        Schema::table('empresa_configuracion', fn (Blueprint $t) => $t->dropColumn([
            'antirrobo_umbral_anulaciones', 'antirrobo_umbral_descuentos', 'antirrobo_umbral_devoluciones', 'antirrobo_umbral_ajustes',
        ]));
    }
};
