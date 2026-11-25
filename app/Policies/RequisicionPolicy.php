<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Requisicion;
use App\Models\User;

class RequisicionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('requisiciones.ver');
    }

    public function view(User $user, Requisicion $requisicion): bool
    {
        return $user->can('requisiciones.ver');
    }

    /** Se crean desde las acciones de la lista, no con el formulario del Resource. */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Requisicion $requisicion): bool
    {
        return false;
    }

    public function delete(User $user, Requisicion $requisicion): bool
    {
        return false;
    }
}
