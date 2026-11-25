<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conteos físicos por localidad: el contador cuenta a ciegas (no ve lo que dice el sistema), un
 * encargado revisa las diferencias, pide reconteo si hace falta y al aprobar se ajusta la
 * localidad. La existencia del sistema se fija por línea EN EL MOMENTO de contarla, así se puede
 * contar con la tienda abierta: lo que se venda después no altera la diferencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conteos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedInteger('numero');
            $table->foreignId('localidad_id')->constrained('localidades')->restrictOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->restrictOnDelete();
            $table->string('tipo', 20);
            $table->string('estado', 20);
            $table->foreignId('asignado_a')->constrained('users')->restrictOnDelete();
            $table->foreignId('creado_por')->constrained('users')->restrictOnDelete();
            $table->foreignId('aprobado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aprobado_en')->nullable();
            $table->timestamp('cerrado_en')->nullable();
            $table->decimal('valor_diferencia', 14, 2)->nullable();
            $table->string('notas', 500)->nullable();
            $table->string('motivo_cancelacion', 255)->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'numero']);
            $table->index(['empresa_id', 'estado']);
            $table->index(['localidad_id', 'estado']);
            $table->index(['asignado_a', 'estado']);
        });

        Schema::create('detalle_conteos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conteo_id')->constrained('conteos')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->decimal('cantidad_contada', 14, 3)->nullable();
            $table->decimal('existencia_al_contar', 14, 3)->nullable();
            $table->decimal('costo_unitario', 14, 2)->nullable();
            $table->foreignId('contado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('contado_en')->nullable();
            $table->unsignedSmallInteger('veces_contado')->default(0);
            $table->timestamps();

            $table->unique(['conteo_id', 'producto_id']);
            $table->index('producto_id');
        });

        Schema::table('empresa_configuracion', function (Blueprint $t) {
            // Ajustes de un conteo por encima de este valor (RD$, en positivo o negativo) los aprueba
            // solo quien administra sucursales.
            $t->decimal('conteo_umbral_aprobacion_admin', 14, 2)->default(5000);
        });
    }

    public function down(): void
    {
        Schema::table('empresa_configuracion', fn (Blueprint $t) => $t->dropColumn('conteo_umbral_aprobacion_admin'));
        Schema::dropIfExists('detalle_conteos');
        Schema::dropIfExists('conteos');
    }
};
