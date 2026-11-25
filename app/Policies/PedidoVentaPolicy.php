<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PedidoVenta;
use App\Models\User;

class PedidoVentaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('pedidos.ver');
    }

    public function view(User $user, PedidoVenta $pedido): bool
    {
        return $user->can('pedidos.ver');
    }

    /** Se crean desde la pantalla "Tomar pedido", no desde el Resource. */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PedidoVenta $pedido): bool
    {
        return false;
    }

    public function delete(User $user, PedidoVenta $pedido): bool
    {
        return false;
    }
}
