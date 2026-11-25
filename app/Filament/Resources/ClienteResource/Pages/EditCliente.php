<?php

namespace App\Filament\Resources\ClienteResource\Pages;

use App\Filament\Resources\ClienteResource;
use App\Models\Cliente;
use App\Services\SaldoFavorService;
use App\Support\MensajeError;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

class EditCliente extends EditRecord
{
    protected static string $resource = ClienteResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('devolverSaldo')
                ->label('Devolver saldo en efectivo')
                ->icon('heroicon-o-banknotes')
                ->color('warning')
                ->visible(fn (): bool => $this->record->tieneSaldoFavor()
                    && auth()->user()?->can('clientes.saldo_favor'))
                ->requiresConfirmation()
                ->modalHeading('Devolver saldo a favor en efectivo')
                ->modalDescription(fn () => 'Saldo disponible: RD$ '.number_format((float) $this->record->saldo_favor, 2))
                ->form([
                    TextInput::make('monto')
                        ->label('Monto a devolver')
                        ->numeric()
                        ->required()
                        ->prefix('RD$')
                        ->minValue(0.01)
                        ->maxValue(fn () => (float) $this->record->saldo_favor)
                        ->default(fn () => (float) $this->record->saldo_favor),
                ])
                ->action(function (array $data): void {
                    try {
                        DB::transaction(function () use ($data): void {
                            app(SaldoFavorService::class)->devolver(
                                cliente: $this->record,
                                empresa: Filament::getTenant(),
                                monto: $data['monto'],
                                userId: auth()->id(),
                                concepto: 'Devolución en efectivo desde panel',
                            );
                        });

                        $this->record->refresh();

                        Notification::make()
                            ->title('Saldo devuelto')
                            ->body('Se devolvieron RD$ '.number_format((float) $data['monto'], 2).' en efectivo.')
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Error al devolver saldo')
                            ->body(MensajeError::para($e))
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
