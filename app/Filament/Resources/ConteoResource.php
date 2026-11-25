<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\EstadoConteo;
use App\Enums\Modulo;
use App\Enums\TipoConteo;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Filament\Resources\ConteoResource\Pages;
use App\Models\Conteo;
use App\Models\DetalleConteo;
use Filament\Facades\Filament;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn as InfolistTableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ConteoResource extends Resource
{
    use RestringidoPorModulo;

    protected static ?string $model = Conteo::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Conteos físicos';

    protected static ?string $modelLabel = 'Conteo físico';

    protected static ?string $pluralModelLabel = 'Conteos físicos';

    protected static ?string $slug = 'conteos';

    protected static string|\UnitEnum|null $navigationGroup = 'Inventario';

    protected static ?int $navigationSort = 4;

    public static function modulo(): Modulo
    {
        return Modulo::INVENTARIO_LOCALIDADES;
    }

    /** Cada sucursal ve sus conteos; el contador sin permiso de crear/aprobar, solo los suyos. */
    public static function getEloquentQuery(): Builder
    {
        $u = auth()->user();
        $sucursal = RequisicionResource::sucursalUsuario();
        $soloAsignados = $u !== null && ! $u->can('conteos.crear') && ! $u->can('conteos.aprobar');

        return parent::getEloquentQuery()
            ->where('conteos.empresa_id', Filament::getTenant()?->id)
            ->when($sucursal !== null, fn (Builder $q) => $q->where('sucursal_id', $sucursal))
            ->when($soloAsignados, fn (Builder $q) => $q->where('asignado_a', $u->id))
            ->with(['localidad.sucursal', 'asignado:id,name'])
            ->withCount('detalles');
    }

    /** Mientras se cuenta, nadie ve lo que dice el sistema: el conteo es a ciegas. */
    public static function esCiego(Conteo $c): bool
    {
        return $c->estado === EstadoConteo::EN_CONTEO;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Conteo')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('numero')->label('Número')->formatStateUsing(fn (Conteo $record) => $record->etiquetaNumero()),
                    TextEntry::make('estado')->label('Estado')->badge()
                        ->formatStateUsing(fn (EstadoConteo $state) => $state->etiqueta())->color(fn (EstadoConteo $state) => $state->color()),
                    TextEntry::make('tipo')->label('Tipo')->formatStateUsing(fn (TipoConteo $state) => $state->etiqueta()),
                    TextEntry::make('localidad.nombre')->label('Localidad')->formatStateUsing(fn (Conteo $record) => $record->localidad->nombreCompleto()),
                    TextEntry::make('asignado.name')->label('Cuenta'),
                    TextEntry::make('aprobador.name')->label('Aprobó')->placeholder('—'),
                    TextEntry::make('valor_diferencia')->label('Diferencia total')->money('DOP')->placeholder('—')
                        ->visible(fn (Conteo $record) => ! static::esCiego($record))
                        ->color(fn ($state) => (float) $state < 0 ? 'danger' : null),
                    TextEntry::make('notas')->label('Notas')->placeholder('—')->columnSpanFull(),
                ]),
            RepeatableEntry::make('detalles')
                ->label('Productos')
                ->columnSpanFull()
                ->table(fn (Conteo $record) => static::esCiego($record)
                    ? [InfolistTableColumn::make('Producto'), InfolistTableColumn::make('Contado')]
                    : [InfolistTableColumn::make('Producto'), InfolistTableColumn::make('Contado'), InfolistTableColumn::make('Sistema'),
                        InfolistTableColumn::make('Diferencia'), InfolistTableColumn::make('Valor (RD$)'), InfolistTableColumn::make('Contó')])
                ->schema(fn (Conteo $record) => static::esCiego($record)
                    ? [TextEntry::make('producto.nombre'), TextEntry::make('cantidad_contada')->numeric(3)->placeholder('Sin contar')]
                    : [
                        TextEntry::make('producto.nombre'),
                        TextEntry::make('cantidad_contada')->numeric(3)->placeholder('—'),
                        TextEntry::make('existencia_al_contar')->numeric(3)->placeholder('—'),
                        TextEntry::make('diferencia')->state(fn (DetalleConteo $record) => $record->diferencia())->numeric(3)
                            ->color(fn ($state) => (float) $state < 0 ? 'danger' : ((float) $state > 0 ? 'warning' : null)),
                        TextEntry::make('valor')->state(fn (DetalleConteo $record) => $record->valorDiferencia())->numeric(2),
                        TextEntry::make('contador.name')->placeholder('—'),
                    ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('numero')->label('Conteo')->formatStateUsing(fn (Conteo $record) => $record->etiquetaNumero())->sortable(),
                TextColumn::make('localidad.nombre')->label('Localidad')->formatStateUsing(fn (Conteo $record) => $record->localidad->nombreCompleto()),
                TextColumn::make('tipo')->label('Tipo')->formatStateUsing(fn (TipoConteo $state) => $state->etiqueta()),
                TextColumn::make('detalles_count')->label('Productos'),
                TextColumn::make('asignado.name')->label('Cuenta'),
                TextColumn::make('estado')->label('Estado')->badge()
                    ->formatStateUsing(fn (EstadoConteo $state) => $state->etiqueta())->color(fn (EstadoConteo $state) => $state->color()),
                TextColumn::make('valor_diferencia')->label('Diferencia')->money('DOP')->placeholder('—')
                    ->formatStateUsing(fn ($state, Conteo $record) => static::esCiego($record) ? '—' : 'RD$ '.number_format((float) $state, 2)),
                TextColumn::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('estado')->label('Estado')
                    ->options(collect(EstadoConteo::cases())->mapWithKeys(fn (EstadoConteo $e) => [$e->value => $e->etiqueta()])),
            ])
            ->recordUrl(fn (Conteo $record) => static::getUrl('view', ['record' => $record]));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListConteos::route('/'),
            'view' => Pages\ViewConteo::route('/{record}'),
        ];
    }
}
