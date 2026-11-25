<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Super-admins, catálogo de permisos y las empresas demo de los cuatro verticales
     * (lavandería, heladería, veterinaria, verdulería), cada uno en sus tres escenarios fiscales:
     * informal, tipo B y e-CF. Ver Database\Seeders\Concerns\SiembraEmpresaDemo.
     */
    public function run(): void
    {
        $this->call([
            FilamentAdminSeeder::class,
            RolePermissionSeeder::class,
            VeterinariaDemoSeeder::class,
            VerduleriaDemoSeeder::class,
        ]);
    }
}
