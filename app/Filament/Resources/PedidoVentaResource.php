<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\EstadoPedidoVenta;
use App\Enums\Modulo;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Filament\Resources\PedidoVentaResource\Pages;
use App\Models\PedidoVenta;
use App\Services\PedidoVentaService;
use App\Support\MensajeError;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PedidoVentaResource extends Resource
{
    use RestringidoPorModulo;

    protected static ?string $model = PedidoVenta::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationLabel = 'Pedidos a caja';

    protected static ?string $modelLabel = 'Pedido';

    protected static ?string $pluralModelLabel = 'Pedidos a caja';

    protected static ?string $slug = 'pedidos';

    protected static string|\UnitEnum|null $navigationGroup = 'Operaciones';

    protected static ?int $navigationSort = 3;

    public static function modulo(): Modulo
    {
        return Modulo::VENTAS_PEDIDOS;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['vendedor:id,name', 'cobradoPor:id,name', 'venta:id,ncf']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('numero')
                    ->label('Pedido')
                    ->formatStateUsing(fn (PedidoVenta $record) => $record->etiquetaNumero())
                    ->sortable(),
                TextColumn::make('nombre_cliente')->label('A nombre de')->searchable(),
                TextColumn::make('vendedor.name')->label('Vendedor'),
                TextColumn::make('total_estimado')->label('Total estimado')->money('DOP')->alignEnd(),
                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoPedidoVenta $state) => $state->etiqueta())
                    ->color(fn (EstadoPedidoVenta $state) => $state->color()),
                TextColumn::make('venta.ncf')->label('Venta')->placeholder('—'),
                TextColumn::make('cobradoPor.name')->label('Cobrado por')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Creado')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(collect(EstadoPedidoVenta::cases())->mapWithKeys(fn (EstadoPedidoVenta $e) => [$e->value => $e->etiqueta()])),
            ])
            ->recordActions([
                Action::make('cancelar')
                    ->label('Cancelar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (PedidoVenta $record) => $record->estaPendiente() && auth()->user()?->can('pedidos.cancelar'))
                    ->schema([
                        TextInput::make('motivo')->label('Motivo')->required()->maxLength(255),
                    ])
                    ->action(function (PedidoVenta $record, array $data): void {
                        if (! auth()->user()?->can('pedidos.cancelar')) {
                            Notification::make()->title('No tienes permiso para cancelar pedidos.')->danger()->send();

                            return;
                        }

                        try {
                            app(PedidoVentaService::class)->cancelar($record, Filament::getTenant(), (int) auth()->id(), $data['motivo']);
                            Notification::make()->title("Pedido {$record->etiquetaNumero()} cancelado")->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title(MensajeError::para($e))->danger()->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPedidosVenta::route('/'),
        ];
    }
}
