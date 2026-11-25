<?php

declare(strict_types=1);

namespace App\Filament\Resources\MermaResource\Pages;

use App\Enums\MotivoMerma;
use App\Exceptions\MermaInvalidaException;
use App\Exceptions\StockInsuficienteException;
use App\Filament\Resources\MermaResource;
use App\Models\Producto;
use App\Services\MermaService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;

class ListMermas extends ListRecords
{
    protected static string $resource = MermaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('registrarMermas')
                ->label('Registrar mermas')
                ->icon('heroicon-o-plus')
                ->modalHeading('Registrar mermas')
                ->modalDescription('Anota lo que se dañó, se pudrió o se venció. Cada línea baja el stock y queda en el Kardex con su costo.')
                ->modalWidth('5xl')
                ->modalSubmitActionLabel('Registrar')
                ->schema([
                    Repeater::make('lineas')
                        ->label('Productos')
                        ->table([
                            TableColumn::make('Producto')->markAsRequired(),
                            TableColumn::make('Cantidad')->markAsRequired(),
                            TableColumn::make('Motivo')->markAsRequired(),
                            TableColumn::make('Nota'),
                        ])
                        ->schema([
                            Select::make('producto_id')
                                ->hiddenLabel()
                                ->searchable()
                                ->required()
                                ->live()
                                // Búsqueda acotada a la empresa activa y a productos con inventario:
                                // nunca una lista completa (puede tener cientos de productos).
                                ->getSearchResultsUsing(fn (string $search): array => Producto::query()
                                    ->where('empresa_id', Filament::getTenant()->id)
                                    ->where('activo', true)
                                    ->where('controla_stock', true)
                                    ->where(fn ($q) => $q
                                        ->whereLikeSinAcentos('nombre', $search)
                                        ->orWhere('codigo', 'ilike', "%{$search}%"))
                                    ->orderBy('nombre')
                                    ->limit(30)
                                    ->pluck('nombre', 'id')
                                    ->all())
                                ->getOptionLabelUsing(fn ($value): ?string => Producto::query()
                                    ->where('empresa_id', Filament::getTenant()->id)
                                    ->whereKey($value)
                                    ->value('nombre')),

                            TextInput::make('cantidad')
                                ->hiddenLabel()
                                ->numeric()
                                ->required()
                                ->minValue(0.001)
                                ->step(0.001)
                                ->suffix(fn (Get $get): ?string => filled($get('producto_id'))
                                    ? Producto::query()->where('empresa_id', Filament::getTenant()->id)->whereKey($get('producto_id'))->value('unidad_base')
                                    : null),

                            Select::make('motivo')
                                ->hiddenLabel()
                                ->options(MotivoMerma::opciones())
                                ->default(MotivoMerma::DANADO->value)
                                ->required()
                                ->native(false),

                            TextInput::make('nota')
                                ->hiddenLabel()
                                ->maxLength(255),
                        ])
                        ->defaultItems(1)
                        ->minItems(1)
                        ->addActionLabel('Agregar producto')
                        ->reorderable(false)
                        ->columnSpanFull(),

                    Textarea::make('observacion')
                        ->label('Nota general')
                        ->placeholder('Ej.: cierre del sábado')
                        ->maxLength(255)
                        ->rows(2),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        $mermas = app(MermaService::class)->registrar(
                            Filament::getTenant(),
                            array_values($data['lineas'] ?? []),
                            auth()->id(),
                            $data['observacion'] ?? null,
                        );
                    } catch (MermaInvalidaException|StockInsuficienteException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                        $action->halt();

                        return;
                    }

                    $valor = $mermas->reduce(fn (string $total, $merma) => bcadd($total, $merma->valorPerdido(), 2), '0.00');

                    Notification::make()
                        ->title($mermas->count() === 1 ? 'Merma registrada' : "{$mermas->count()} mermas registradas")
                        ->body('Valor perdido: RD$ '.number_format((float) $valor, 2))
                        ->success()
                        ->send();
                }),
        ];
    }
}
