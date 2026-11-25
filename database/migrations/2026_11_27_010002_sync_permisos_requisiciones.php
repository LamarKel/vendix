<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permisos de requisiciones y movimientos internos para los roles base de las empresas existentes de cada empresa
 * ya existente (las empresas nuevas los reciben de RolesEmpresaService::sembrarRolesBase()).
 */
return new class extends Migration
{
    private const PERMISOS = ['inventario.mover', 'requisiciones.ver', 'requisiciones.crear', 'requisiciones.despachar', 'requisiciones.recibir', 'requisiciones.aprobar'];

    private const POR_ROL = [
        'Administrador' => self::PERMISOS,
        'Almacenista' => ['inventario.mover', 'requisiciones.ver', 'requisiciones.crear', 'requisiciones.despachar', 'requisiciones.recibir'],
        'Vendedor' => ['requisiciones.ver', 'requisiciones.crear', 'requisiciones.recibir'],
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
