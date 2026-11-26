<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Colores por defecto de los PDF: los de Vendix (verde y azul noche) en vez del cian heredado.
 * Solo se cambian las empresas que seguían con el valor por defecto anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresa_configuracion', function ($table) {
            $table->string('color_primario', 7)->default('#0C8A63')->change();
            $table->string('color_secundario', 7)->default('#0B1B2B')->change();
        });

        DB::table('empresa_configuracion')->where('color_primario', '#00C9D7')->update(['color_primario' => '#0C8A63']);
        DB::table('empresa_configuracion')->where('color_secundario', '#101820')->update(['color_secundario' => '#0B1B2B']);
    }

    public function down(): void
    {
        Schema::table('empresa_configuracion', function ($table) {
            $table->string('color_primario', 7)->default('#00C9D7')->change();
            $table->string('color_secundario', 7)->default('#101820')->change();
        });
    }
};
