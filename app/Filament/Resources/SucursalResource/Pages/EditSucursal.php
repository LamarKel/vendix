<?php

declare(strict_types=1);

namespace App\Filament\Resources\SucursalResource\Pages;

use App\Filament\Resources\SucursalResource;
use Filament\Resources\Pages\EditRecord;

class EditSucursal extends EditRecord
{
    protected static string $resource = SucursalResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->record->es_principal) {
            $data['activa'] = true;
        }

        return $data;
    }
}
