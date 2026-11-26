<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Un pedido a caja puede venir de una cotización aprobada (se cobra al precio cotizado). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos_venta', function (Blueprint $table) {
            $table->foreignId('cotizacion_id')->nullable()->after('cliente_id')->constrained('cotizaciones')->restrictOnDelete();
            $table->index('cotizacion_id');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos_venta', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cotizacion_id');
        });
    }
};
