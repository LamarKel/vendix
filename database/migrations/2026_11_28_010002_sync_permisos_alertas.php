<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permisos de alertas de recepción y de control antirrobo para los roles base existentes de cada empresa
 * ya existente (las empresas nuevas los reciben de RolesEmpresaService::sembrarRolesBase()).
 */
return new class extends Migration
{
    private const PERMISOS = ['notificaciones.recepcion_pendiente', 'notificaciones.antirrobo', 'reportes.control'];

    private const POR_ROL = [
        'Administrador' => self::PERMISOS,
        'Almacenista' => ['notificaciones.recepcion_pendiente'],
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
