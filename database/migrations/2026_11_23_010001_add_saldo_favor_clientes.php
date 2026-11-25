<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->decimal('saldo_favor', 14, 2)->default(0)->after('activo');
        });

        Schema::create('movimientos_saldo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->string('tipo'); // deposito, consumo, devolucion
            $table->decimal('monto', 14, 2);
            $table->decimal('saldo_anterior', 14, 2);
            $table->decimal('saldo_posterior', 14, 2);
            $table->string('concepto')->nullable();
            $table->foreignId('venta_id')->nullable()->constrained('ventas')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('empresa_id');
            $table->index('cliente_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_saldo');

        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn('saldo_favor');
        });
    }
};
