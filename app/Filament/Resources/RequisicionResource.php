<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\EstadoRequisicion;
use App\Enums\Modulo;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Filament\Resources\RequisicionResource\Pages;
use App\Models\Localidad;
use App\Models\Producto;
use App\Models\Requisicion;
use App\Models\StockLocalidad;
use App\Services\LocalidadService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn as InfolistTableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RequisicionResource extends Resource
{
    use RestringidoPorModulo;

    protected static ?string $model = Requisicion::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationLabel = 'Requisiciones';

    protected static ?string $modelLabel = 'Requisición';

    protected static ?string $pluralModelLabel = 'Requisiciones';

    protected static ?string $slug = 'requisiciones';

    protected static string|\UnitEnum|null $navigationGroup = 'Inventario';

    protected static ?int $navigationSort = 3;

    public static function modulo(): Modulo
    {
        return Modulo::INVENTARIO_LOCALIDADES;
    }

    /** Sucursal del usuario actual (null = administra todas). */
    public static function sucursalUsuario(): ?int
    {
        $usuario = auth()->user();

        if ($usuario === null || $usuario->can('sucursales.administrar')) {
            return null;
        }

        return $usuario->sucursal_id ?? app(LocalidadService::class)->principal(Filament::getTenant())->id;
    }

    /** Cada sucursal ve las requisiciones en las que participa (pide o surte). */
    public static function getEloquentQuery(): Builder
    {
        $sucursal = static::sucursalUsuario();

        return parent::getEloquentQuery()
            ->where('requisiciones.empresa_id', Filament::getTenant()?->id)
            ->when($sucursal !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('sucursal_origen_id', $sucursal)
                ->orWhere('sucursal_destino_id', $sucursal)))
            ->with(['localidadOrigen.sucursal', 'localidadDestino.sucursal', 'solicitante:id,name', 'detalles.producto']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Requisición')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('numero')->label('Número')->formatStateUsing(fn (Requisicion $record) => $record->etiquetaNumero()),
                    TextEntry::make('estado')->label('Estado')->badge()
                        ->formatStateUsing(fn (EstadoRequisicion $state) => $state->etiqueta())
                        ->color(fn (EstadoRequisicion $state) => $state->color()),
                    TextEntry::make('tipo')->label('Tipo')->formatStateUsing(fn (Requisicion $record) => $record->esMovimientoInterno() ? 'Movimiento interno' : ($record->entre_sucursales ? 'Entre sucursales' : 'Misma sucursal')),
                    TextEntry::make('localidadOrigen.nombre')->label('Surte')->formatStateUsing(fn (Requisicion $record) => $record->localidadOrigen->nombreCompleto()),
                    TextEntry::make('localidadDestino.nombre')->label('Pide / recibe')->formatStateUsing(fn (Requisicion $record) => $record->localidadDestino->nombreCompleto()),
                    TextEntry::make('solicitante.name')->label('Solicitó'),
                    TextEntry::make('aprobador.name')->label('Aprobó')->placeholder('—'),
                    TextEntry::make('despachador.name')->label('Despachó')->placeholder('—'),
                    TextEntry::make('receptor.name')->label('Recibió')->placeholder('—'),
                    TextEntry::make('created_at')->label('Creada')->dateTime('d/m/Y H:i'),
                    TextEntry::make('despachado_en')->label('Despachada')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('recibido_en')->label('Recibida')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('notas')->label('Notas')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('motivo_cancelacion')->label('Motivo de cancelación / rechazo')->placeholder('—')->columnSpanFull()
                        ->visible(fn (Requisicion $record) => filled($record->motivo_cancelacion)),
                ]),
            RepeatableEntry::make('detalles')
                ->label('Productos')
                ->columnSpanFull()
                ->table([
                    InfolistTableColumn::make('Producto'),
                    InfolistTableColumn::make('Pedido'),
                    InfolistTableColumn::make('Despachado'),
                    InfolistTableColumn::make('Recibido'),
                    InfolistTableColumn::make('Faltante'),
                    InfolistTableColumn::make('Notas'),
                ])
                ->schema([
                    TextEntry::make('producto.nombre'),
                    TextEntry::make('cantidad_solicitada')->numeric(3),
                    TextEntry::make('cantidad_despachada')->numeric(3)->placeholder('—'),
                    TextEntry::make('cantidad_recibida')->numeric(3)->placeholder('—'),
                    TextEntry::make('faltante')->state(fn ($record) => $record->faltante())->numeric(3)->placeholder('—')
                        ->color(fn ($state) => (float) $state > 0 ? 'danger' : null),
                    TextEntry::make('nota_despacho')->state(fn ($record) => collect([$record->nota_despacho, $record->nota_recepcion])->filter()->implode(' · '))->placeholder('—'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('numero')->label('Número')->formatStateUsing(fn (Requisicion $record) => $record->etiquetaNumero())->sortable(),
                TextColumn::make('localidadOrigen.nombre')->label('Surte')->formatStateUsing(fn (Requisicion $record) => $record->localidadOrigen->nombreCompleto()),
                TextColumn::make('localidadDestino.nombre')->label('Pide / recibe')->formatStateUsing(fn (Requisicion $record) => $record->localidadDestino->nombreCompleto()),
                TextColumn::make('estado')->label('Estado')->badge()
                    ->formatStateUsing(fn (EstadoRequisicion $state) => $state->etiqueta())
                    ->color(fn (EstadoRequisicion $state) => $state->color()),
                TextColumn::make('solicitante.name')->label('Solicitó'),
                TextColumn::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('estado')->label('Estado')
                    ->options(collect(EstadoRequisicion::cases())->mapWithKeys(fn (EstadoRequisicion $e) => [$e->value => $e->etiqueta()])),
                SelectFilter::make('bandeja')->label('Bandeja')
                    ->options(['despachar' => 'Por despachar (mi sucursal surte)', 'recibir' => 'Por recibir (mi sucursal pide)'])
                    ->query(function (Builder $q, array $data) {
                        $sucursal = static::sucursalUsuario();
                        return match ($data['value'] ?? null) {
                            'despachar' => $q->where('estado', EstadoRequisicion::PENDIENTE->value)->when($sucursal, fn ($w) => $w->where('sucursal_origen_id', $sucursal)),
                            'recibir' => $q->where('estado', EstadoRequisicion::DESPACHADA->value)->when($sucursal, fn ($w) => $w->where('sucursal_destino_id', $sucursal)),
                            default => $q,
                        };
                    }),
                SelectFilter::make('tipo')->label('Tipo')->options([Requisicion::TIPO_REQUISICION => 'Requisición', Requisicion::TIPO_MOVIMIENTO => 'Movimiento interno']),
            ])
            ->recordUrl(fn (Requisicion $record) => static::getUrl('view', ['record' => $record]));
    }

    /**
     * Localidades para elegir. Las de la sucursal del usuario con su nombre; las de otras
     * sucursales también (para pedirles), pero el stock que se muestra de ellas es solo el total.
     *
     * @return array<string, array<int, string>>
     */
    public static function opcionesLocalidades(bool $soloMiSucursal = false, bool $sinRecepcion = false): array
    {
        $sucursal = static::sucursalUsuario();

        return Localidad::query()
            ->where('empresa_id', Filament::getTenant()->id)
            ->where('activa', true)
            ->when($sinRecepcion, fn ($q) => $q->where('tipo', '!=', \App\Enums\TipoLocalidad::RECEPCION->value))
            ->when($soloMiSucursal && $sucursal !== null, fn ($q) => $q->where('sucursal_id', $sucursal))
            ->with('sucursal:id,nombre')
            ->orderBy('sucursal_id')->orderBy('nombre')
            ->get()
            ->groupBy(fn (Localidad $l) => $l->sucursal->nombre)
            ->map(fn ($grupo) => $grupo->mapWithKeys(fn (Localidad $l) => [$l->id => "{$l->sucursal->nombre} · {$l->nombre}"])->all())
            ->all();
    }

    /**
     * Existencia visible según la regla: de una localidad de MI sucursal, su cantidad; de otra
     * sucursal, solo el total de esa sucursal.
     */
    public static function existenciaVisible(?int $localidadId, ?int $productoId): ?string
    {
        if (! $localidadId || ! $productoId) {
            return null;
        }

        $localidad = Localidad::where('empresa_id', Filament::getTenant()->id)->with('sucursal')->find($localidadId);

        if ($localidad === null) {
            return null;
        }

        $sucursal = static::sucursalUsuario();

        if ($sucursal === null || $sucursal === $localidad->sucursal_id) {
            $cantidad = StockLocalidad::where('localidad_id', $localidad->id)->where('producto_id', $productoId)->value('cantidad') ?? 0;

            return "Disponible en {$localidad->nombre}: ".number_format((float) $cantidad, 2);
        }

        $total = StockLocalidad::query()
            ->join('localidades', 'localidades.id', '=', 'stock_localidades.localidad_id')
            ->where('localidades.sucursal_id', $localidad->sucursal_id)
            ->where('stock_localidades.producto_id', $productoId)
            ->sum('stock_localidades.cantidad');

        return "Total en {$localidad->sucursal->nombre}: ".number_format((float) $total, 2);
    }

    /** Repeater de productos (producto + cantidad) con la existencia visible del origen. */
    public static function repeaterProductos(string $campoOrigen = 'localidad_origen_id'): Repeater
    {
        return Repeater::make('lineas')
            ->label('Productos')
            ->table([
                TableColumn::make('Producto')->markAsRequired(),
                TableColumn::make('Cantidad')->markAsRequired(),
            ])
            ->schema([
                Select::make('producto_id')
                    ->hiddenLabel()
                    ->searchable()
                    ->required()
                    ->live()
                    ->getSearchResultsUsing(fn (string $search): array => Producto::query()
                        ->where('empresa_id', Filament::getTenant()->id)
                        ->where('activo', true)
                        ->where('controla_stock', true)
                        ->where(fn ($q) => $q->whereLikeSinAcentos('nombre', $search)->orWhere('codigo', 'ilike', "%{$search}%"))
                        ->orderBy('nombre')->limit(30)->pluck('nombre', 'id')->all())
                    ->getOptionLabelUsing(fn ($value): ?string => Producto::query()
                        ->where('empresa_id', Filament::getTenant()->id)->whereKey($value)->value('nombre'))
                    ->helperText(fn (Get $get) => static::existenciaVisible((int) $get("../../{$campoOrigen}"), (int) $get('producto_id'))),
                TextInput::make('cantidad')->hiddenLabel()->numeric()->required()->minValue(0.001)->step(0.001),
            ])
            ->defaultItems(1)
            ->minItems(1)
            ->addActionLabel('Agregar producto')
            ->reorderable(false)
            ->columnSpanFull();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRequisiciones::route('/'),
            'view' => Pages\ViewRequisicion::route('/{record}'),
        ];
    }
}
