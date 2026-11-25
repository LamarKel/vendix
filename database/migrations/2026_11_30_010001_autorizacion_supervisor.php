<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Support\Facades\Schema;

/**
 * Descuentos solo con autorización de supervisor: PIN personal (hash) por usuario y, en la venta,
 * quién autorizó el descuento. Permiso ventas.autorizar_descuento para el Administrador existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->string('pin_autorizacion')->nullable());
        Schema::table('ventas', fn (Blueprint $t) => $t->foreignId('descuento_autorizado_por')->nullable()->after('descuento_id')->constrained('users')->nullOnDelete());

        $permiso = Permission::firstOrCreate(['name' => 'ventas.autorizar_descuento', 'guard_name' => 'web']);
        foreach (DB::table('roles')->where('name', 'Administrador')->pluck('id') as $rolId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permiso->id, 'role_id' => $rolId]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('ventas', fn (Blueprint $t) => $t->dropConstrainedForeignId('descuento_autorizado_por'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('pin_autorizacion'));
    }
};
