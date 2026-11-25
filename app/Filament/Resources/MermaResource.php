<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Modulo;
use App\Enums\MotivoMerma;
use App\Enums\OrigenMovimiento;
use App\Enums\TipoMovimiento;
use App\Exceptions\MermaInvalidaException;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Filament\Resources\MermaResource\Pages;
use App\Models\MovimientoInventario;
use App\Services\MermaService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Mermas: vista del Kardex filtrada a las salidas con origen "merma" (las registradas aquí y las
 * de devoluciones de clientes con destino merma). Se registran en bloque desde la acción
 * "Registrar mermas" de la lista y se anulan con un movimiento contrario; nada se edita ni borra.
 */
class MermaResource extends Resource
{
    use RestringidoPorModulo;

    protected static ?string $model = MovimientoInventario::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-trash';

    protected static ?string $navigationLabel = 'Mermas';

    protected static ?string $modelLabel = 'Merma';

    protected static ?string $pluralModelLabel = 'Mermas';

    protected static ?string $slug = 'mermas';

    protected static string|\UnitEnum|null $navigationGroup = 'Inventario';

    protected static ?int $navigationSort = 4;

    public static function modulo(): Modulo
    {
        return Modulo::INVENTARIO_MERMAS;
    }

    /** Su propio permiso, no el del Kardex (MovimientoInventarioPolicy pide kardex.ver). */
    public static function puedeAccederPorPermiso(): bool
    {
        return static::canViewAny();
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('inventario.mermas') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        // empresa_id explícito además del scope de tenant de Filament: el scope solo se registra
        // en el ciclo de una request del panel, y este query también lo usa totalPerdido().
        return parent::getEloquentQuery()
            ->where('movimientos_inventario.empresa_id', Filament::getTenant()?->id)
            ->where('origen', OrigenMovimiento::MERMA)
            ->where('tipo', TipoMovimiento::SALIDA)
            ->with(['producto', 'user', 'reversion']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y h:i A')
                    ->sortable(),

                TextColumn::make('producto.nombre')
                    ->label('Producto')
                    ->searchable(),

                TextColumn::make('cantidad')
                    ->label('Cantidad')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(fn (MovimientoInventario $record): string => ' '.($record->producto?->unidad_base ?? ''))
                    ->alignEnd(),

                TextColumn::make('motivo')
                    ->label('Motivo')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof MotivoMerma ? $state->etiqueta() : '—')
                    ->placeholder('Devolución de cliente')
                    ->color(fn ($state): string => match ($state) {
                        MotivoMerma::DANADO, MotivoMerma::VENCIDO => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('costo_unitario')
                    ->label('Costo')
                    ->money('DOP')
                    ->placeholder('—')
                    ->alignEnd(),

                TextColumn::make('valor_perdido')
                    ->label('Valor perdido')
                    ->state(fn (MovimientoInventario $record): string => $record->valorPerdido())
                    ->money('DOP')
                    ->alignEnd()
                    ->summarize(
                        Summarizer::make()
                            ->label('Total perdido (sin anuladas)')
                            ->using(fn (QueryBuilder $query) => static::totalPerdido($query))
                            ->money('DOP'),
                    ),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->state(fn (MovimientoInventario $record): string => $record->reversion !== null ? 'Anulada' : 'Vigente')
                    ->color(fn (string $state): string => $state === 'Anulada' ? 'gray' : 'success'),

                TextColumn::make('user.name')
                    ->label('Registró')
                    ->placeholder('Sistema')
                    ->toggleable(),

                TextColumn::make('observacion')
                    ->label('Nota')
                    ->placeholder('—')
                    ->limit(40)
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('fecha')
                    ->schema([
                        DatePicker::make('desde')->label('Desde'),
                        DatePicker::make('hasta')->label('Hasta'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['desde'] ?? null, fn (Builder $q, $desde) => $q->whereDate('created_at', '>=', $desde))
                        ->when($data['hasta'] ?? null, fn (Builder $q, $hasta) => $q->whereDate('created_at', '<=', $hasta))),

                SelectFilter::make('motivo')
                    ->label('Motivo')
                    ->options(MotivoMerma::opciones()),

                SelectFilter::make('producto_id')
                    ->label('Producto')
                    // Scope manual: Filament no aplica el tenant dentro de ->relationship().
                    ->relationship('producto', 'nombre', modifyQueryUsing: fn (Builder $query) => $query->where('empresa_id', Filament::getTenant()->id))
                    ->searchable()
                    ->preload(),

                TernaryFilter::make('anulada')
                    ->label('Estado')
                    ->placeholder('Todas')
                    ->trueLabel('Solo anuladas')
                    ->falseLabel('Solo vigentes')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('reversion'),
                        false: fn (Builder $query) => $query->whereDoesntHave('reversion'),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->recordActions([
                Action::make('anular')
                    ->label('Anular')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (MovimientoInventario $record): bool => $record->esMermaManual()
                        && $record->reversion === null
                        && (auth()->user()?->can('inventario.mermas_anular') ?? false))
                    ->modalHeading('Anular merma')
                    ->modalDescription(fn (MovimientoInventario $record): string => 'Se devolverán '
                        .number_format((float) $record->cantidad, 2).' '.($record->producto?->unidad_base ?? '')
                        .' de «'.($record->producto?->nombre ?? '').'» al stock.')
                    ->schema([
                        Textarea::make('razon')
                            ->label('¿Por qué se anula?')
                            ->required()
                            ->maxLength(255)
                            ->rows(2),
                    ])
                    ->action(function (MovimientoInventario $record, array $data): void {
                        try {
                            app(MermaService::class)->anular($record, Filament::getTenant(), $data['razon'], auth()->id());
                        } catch (MermaInvalidaException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Merma anulada: el stock se devolvió')->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Sin mermas registradas')
            ->emptyStateDescription('Usa «Registrar mermas» para anotar lo que se dañó, se pudrió o se venció.');
    }

    /**
     * Valor de las mermas del listado (con los filtros aplicados), sin contar las anuladas. Las
     * anteriores al campo costo_unitario usan el costo actual del producto.
     */
    public static function totalPerdido(QueryBuilder $query): float
    {
        return (float) $query
            ->whereNotExists(fn (QueryBuilder $sub) => $sub
                ->selectRaw('1')
                ->from('movimientos_inventario as reversiones')
                ->whereColumn('reversiones.revierte_movimiento_id', 'movimientos_inventario.id'))
            ->sum(DB::raw('movimientos_inventario.cantidad * COALESCE(movimientos_inventario.costo_unitario, (SELECT productos.costo FROM productos WHERE productos.id = movimientos_inventario.producto_id), 0)'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMermas::route('/'),
        ];
    }
}
