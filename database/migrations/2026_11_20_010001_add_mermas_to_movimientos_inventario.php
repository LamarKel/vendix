<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Mermas sobre el Kardex (sin tablas propias): cada merma es un movimiento SALIDA con origen
 * "merma", su motivo y el costo del producto en ese momento. Anular una merma registra el
 * movimiento contrario (ENTRADA) que apunta al original por revierte_movimiento_id; el índice
 * único impide revertir dos veces el mismo movimiento aunque dos usuarios anulen a la vez.
 *
 * costo_unitario se guarda en TODOS los movimientos a partir de ahora (costo promedio del
 * producto antes del movimiento): los anteriores quedan en null y los reportes usan el costo
 * actual del producto como aproximación, igual que con detalle_ventas.costo_unitario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->string('motivo', 30)->nullable()->after('origen');
            $table->decimal('costo_unitario', 14, 2)->nullable()->after('cantidad');
            $table->foreignId('revierte_movimiento_id')
                ->nullable()
                ->after('referencia_id')
                ->unique()
                ->constrained('movimientos_inventario')
                ->restrictOnDelete();

            $table->index(['empresa_id', 'origen', 'created_at']);
        });

        foreach (['inventario.mermas', 'inventario.mermas_anular'] as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }

        // Roles ya existentes (son por empresa): el Administrador tiene todo el catálogo y el
        // Almacenista, que ya ajusta stock, registra mermas; anularlas queda para Administrador.
        Role::query()->where('name', 'Administrador')->each(
            fn (Role $rol) => $rol->givePermissionTo(['inventario.mermas', 'inventario.mermas_anular'])
        );
        Role::query()->where('name', 'Almacenista')->each(
            fn (Role $rol) => $rol->givePermissionTo('inventario.mermas')
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->dropIndex(['empresa_id', 'origen', 'created_at']);
            $table->dropConstrainedForeignId('revierte_movimiento_id');
            $table->dropColumn(['motivo', 'costo_unitario']);
        });

        // No se eliminan los permisos al revertir: podrían estar asignados a roles.
    }
};
