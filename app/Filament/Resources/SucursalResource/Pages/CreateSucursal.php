<?php

declare(strict_types=1);

namespace App\Filament\Resources\SucursalResource\Pages;

use App\Filament\Resources\SucursalResource;
use App\Services\LocalidadService;
use Filament\Resources\Pages\CreateRecord;

class CreateSucursal extends CreateRecord
{
    protected static string $resource = SucursalResource::class;

    /** Toda sucursal nueva arranca con Tienda (venta), Recibo (recepción) y Almacén. */
    protected function afterCreate(): void
    {
        app(LocalidadService::class)->asegurarLocalidadesBase($this->record);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
