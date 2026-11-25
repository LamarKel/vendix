<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permisos de "Pedidos a caja": se crean y se dan al Administrador y al Vendedor de cada empresa
 * ya existente (las empresas nuevas los reciben de RolesEmpresaService::sembrarRolesBase()).
 */
return new class extends Migration
{
    private const PERMISOS = ['pedidos.crear', 'pedidos.ver', 'pedidos.cobrar', 'pedidos.cancelar'];

    private const POR_ROL = [
        'Administrador' => self::PERMISOS,
        'Vendedor' => ['pedidos.crear', 'pedidos.ver', 'pedidos.cobrar'],
    ];

    public function up(): void
    {
        $ids = [];
        foreach (self::PERMISOS as $nombre) {
            $ids[$nombre] = Permission::firstOrCreate(['name' => $nombre, 'guard_name' => 'web'])->id;
        }

        foreach (self::POR_ROL as $rol => $permisos) {
            $roles = DB::table('roles')->where('name', $rol)->where('guard_name', 'web')->pluck('id');

            foreach ($roles as $rolId) {
                foreach ($permisos as $permiso) {
                    DB::table('role_has_permissions')->insertOrIgnore([
                        'permission_id' => $ids[$permiso],
                        'role_id' => $rolId,
                    ]);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // No se eliminan permisos al revertir — podrían estar asignados a roles.
    }
};
