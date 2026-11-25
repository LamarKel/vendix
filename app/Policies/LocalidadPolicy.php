<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Localidad;
use App\Models\User;

class LocalidadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sucursales.administrar');
    }

    public function view(User $user, Localidad $localidad): bool
    {
        return $user->can('sucursales.administrar');
    }

    public function create(User $user): bool
    {
        return $user->can('sucursales.administrar');
    }

    public function update(User $user, Localidad $localidad): bool
    {
        return $user->can('sucursales.administrar');
    }

    public function delete(User $user, Localidad $localidad): bool
    {
        return false;
    }
}
