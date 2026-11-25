<?php

declare(strict_types=1);

namespace App\Filament\Resources\PedidoVentaResource\Pages;

use App\Filament\Resources\PedidoVentaResource;
use Filament\Resources\Pages\ListRecords;

class ListPedidosVenta extends ListRecords
{
    protected static string $resource = PedidoVentaResource::class;
}
