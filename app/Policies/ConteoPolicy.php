<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Conteo;
use App\Models\User;

class ConteoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('conteos.ver');
    }

    public function view(User $user, Conteo $conteo): bool
    {
        return $user->can('conteos.ver');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Conteo $conteo): bool
    {
        return false;
    }

    public function delete(User $user, Conteo $conteo): bool
    {
        return false;
    }
}
