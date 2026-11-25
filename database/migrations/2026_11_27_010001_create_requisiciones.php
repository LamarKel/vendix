<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requisiciones entre localidades (misma sucursal o entre sucursales) y movimientos internos.
 *
 * Flujo de una requisición: [pendiente de aprobación] → pendiente → despachada (sale del origen,
 * queda en tránsito) → recibida (entra al destino; lo que no llegó queda como faltante).
 * Un movimiento interno es el mismo documento hecho en un solo paso (tipo = movimiento).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisiciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedInteger('numero');
            $table->string('tipo', 20)->default('requisicion');
            $table->string('estado', 30);
            $table->foreignId('localidad_origen_id')->constrained('localidades')->restrictOnDelete();
            $table->foreignId('localidad_destino_id')->constrained('localidades')->restrictOnDelete();
            $table->foreignId('sucursal_origen_id')->constrained('sucursales')->restrictOnDelete();
            $table->foreignId('sucursal_destino_id')->constrained('sucursales')->restrictOnDelete();
            $table->boolean('entre_sucursales')->default(false);
            $table->string('notas', 500)->nullable();
            $table->foreignId('solicitado_por')->constrained('users')->restrictOnDelete();
            $table->foreignId('aprobado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aprobado_en')->nullable();
            $table->foreignId('despachado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('despachado_en')->nullable();
            $table->foreignId('recibido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recibido_en')->nullable();
            $table->foreignId('cancelado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelado_en')->nullable();
            $table->string('motivo_cancelacion', 255)->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'numero']);
            $table->index(['empresa_id', 'estado']);
            $table->index(['sucursal_origen_id', 'estado']);
            $table->index(['sucursal_destino_id', 'estado']);
        });

        Schema::create('detalle_requisiciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisicion_id')->constrained('requisiciones')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->decimal('cantidad_solicitada', 14, 3);
            $table->decimal('cantidad_despachada', 14, 3)->nullable();
            $table->decimal('cantidad_recibida', 14, 3)->nullable();
            $table->string('nota_despacho', 255)->nullable();
            $table->string('nota_recepcion', 255)->nullable();
            $table->timestamps();

            $table->index('requisicion_id');
            $table->index('producto_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detalle_requisiciones');
        Schema::dropIfExists('requisiciones');
    }
};
