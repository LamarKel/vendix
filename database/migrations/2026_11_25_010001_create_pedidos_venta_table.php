<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos a caja: el vendedor de piso arma el pedido a nombre del cliente y la caja lo busca por
 * ese nombre (o por número) para cobrarlo. El pedido no reserva stock ni fija precios: al cobrar,
 * el POS vuelve a leer precio, factor y stock de la base, como con cualquier carrito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos_venta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->unsignedInteger('numero');
            $table->string('nombre_cliente', 120);
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->foreignId('vendedor_id')->constrained('users')->restrictOnDelete();
            $table->string('estado', 20)->default('pendiente');
            $table->string('notas', 500)->nullable();
            $table->decimal('total_estimado', 14, 2)->default(0);
            $table->foreignId('venta_id')->nullable()->constrained('ventas')->nullOnDelete();
            $table->foreignId('cobrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cobrado_en')->nullable();
            $table->foreignId('cancelado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelado_en')->nullable();
            $table->string('motivo_cancelacion', 255)->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'numero']);
            $table->index(['empresa_id', 'estado', 'created_at']);
            $table->index('venta_id');
        });

        Schema::create('detalle_pedidos_venta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_venta_id')->constrained('pedidos_venta')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->foreignId('presentacion_id')->nullable()->constrained('producto_presentaciones')->nullOnDelete();
            $table->string('descripcion', 255);
            $table->decimal('cantidad', 14, 3);
            $table->decimal('precio_estimado', 14, 2)->default(0);
            $table->timestamps();

            $table->index('pedido_venta_id');
            $table->index('producto_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detalle_pedidos_venta');
        Schema::dropIfExists('pedidos_venta');
    }
};
