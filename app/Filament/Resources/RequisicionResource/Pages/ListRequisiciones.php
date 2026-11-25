<?php

declare(strict_types=1);

namespace App\Filament\Resources\RequisicionResource\Pages;

use App\Filament\Resources\RequisicionResource;
use App\Services\RequisicionService;
use App\Support\MensajeError;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListRequisiciones extends ListRecords
{
    protected static string $resource = RequisicionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('nuevaRequisicion')
                ->label('Nueva requisición')
                ->icon('heroicon-o-plus')
                ->visible(fn () => auth()->user()?->can('requisiciones.crear'))
                ->modalHeading('Pedir mercancía')
                ->modalDescription('Tu localidad pide a otra (de tu sucursal o de otra sucursal). Quien surte la despacha y tú confirmas lo que llegó.')
                ->modalWidth('5xl')
                ->modalSubmitActionLabel('Enviar requisición')
                ->schema([
                    Select::make('localidad_destino_id')->label('¿Para qué localidad? (la tuya)')
                        ->options(fn () => RequisicionResource::opcionesLocalidades(soloMiSucursal: true))->required()->native(false),
                    Select::make('localidad_origen_id')->label('¿A quién se lo pides?')
                        ->options(fn () => RequisicionResource::opcionesLocalidades())->required()->live()->native(false),
                    RequisicionResource::repeaterProductos(),
                    Textarea::make('notas')->label('Notas')->maxLength(500)->rows(2),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        $r = app(RequisicionService::class)->crear(
                            Filament::getTenant(), auth()->user(),
                            (int) $data['localidad_origen_id'], (int) $data['localidad_destino_id'],
                            array_values($data['lineas'] ?? []), $data['notas'] ?? null,
                        );
                    } catch (\Throwable $e) {
                        Notification::make()->title(MensajeError::para($e))->danger()->send();
                        $action->halt();

                        return;
                    }

                    Notification::make()->title("Requisición {$r->etiquetaNumero()} enviada")->body($r->estado->etiqueta())->success()->send();
                }),

            Action::make('movimientoInterno')
                ->label('Movimiento interno')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('gray')
                ->visible(fn () => auth()->user()?->can('inventario.mover'))
                ->modalHeading('Mover entre localidades de tu sucursal')
                ->modalDescription('Un solo paso: p. ej. de Recibo a Almacén. Queda en el Kardex con tu nombre.')
                ->modalWidth('5xl')
                ->modalSubmitActionLabel('Mover')
                ->schema([
                    Select::make('localidad_origen_id')->label('Desde')
                        ->options(fn () => RequisicionResource::opcionesLocalidades(soloMiSucursal: true))->required()->live()->native(false),
                    Select::make('localidad_destino_id')->label('Hacia')
                        ->options(fn () => RequisicionResource::opcionesLocalidades(soloMiSucursal: true))->required()->native(false),
                    RequisicionResource::repeaterProductos(),
                    Textarea::make('notas')->label('Notas')->maxLength(500)->rows(2),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        $r = app(RequisicionService::class)->moverInterno(
                            Filament::getTenant(), auth()->user(),
                            (int) $data['localidad_origen_id'], (int) $data['localidad_destino_id'],
                            array_values($data['lineas'] ?? []), $data['notas'] ?? null,
                        );
                    } catch (\Throwable $e) {
                        Notification::make()->title(MensajeError::para($e))->danger()->send();
                        $action->halt();

                        return;
                    }

                    Notification::make()->title("Movimiento {$r->etiquetaNumero()} registrado")->success()->send();
                }),
        ];
    }
}
