<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Sucursal;
use App\Models\User;

class SucursalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sucursales.administrar');
    }

    public function view(User $user, Sucursal $sucursal): bool
    {
        return $user->can('sucursales.administrar');
    }

    public function create(User $user): bool
    {
        return $user->can('sucursales.administrar');
    }

    public function update(User $user, Sucursal $sucursal): bool
    {
        return $user->can('sucursales.administrar');
    }

    public function delete(User $user, Sucursal $sucursal): bool
    {
        return false;
    }
}
