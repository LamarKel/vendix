<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\EstadoCotizacion;
use App\Enums\Modulo;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Filament\Resources\CotizacionResource\Pages;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Producto;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CotizacionResource extends Resource
{
    use RestringidoPorModulo;

    protected static ?string $model = Cotizacion::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static string|UnitEnum|null $navigationGroup = 'Comercial';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Cotizaciones';

    protected static ?string $slug = 'cotizaciones';

    protected static ?string $pluralModelLabel = 'Cotizaciones';

    protected static ?string $modelLabel = 'Cotización';

    public static function modulo(): Modulo
    {
        return Modulo::COTIZACIONES;
    }

    public static function form(Schema $schema): Schema
    {
        $empresaId = Filament::getTenant()?->id;

        // Mismo esquema que Facturación: tarjeta con los datos del cliente arriba, buscador de
        // productos y debajo la tabla donde se van agregando las líneas.
        return $schema->components([
            Section::make('Cliente')
                ->icon(Heroicon::OutlinedUser)
                ->schema([
                    Select::make('cliente_id')
                        ->label('Cliente')
                        ->options(fn () => Cliente::where('empresa_id', $empresaId)
                            ->where('activo', true)
                            ->get()
                            ->mapWithKeys(fn (Cliente $c) => [$c->id => $c->documento ? "{$c->nombre} · {$c->documento}" : $c->nombre]))
                        ->searchable()
                        ->placeholder('Sin cliente (cotización genérica)')
                        ->columnSpan(2),

                    DatePicker::make('fecha')
                        ->label('Fecha')
                        ->default(fn () => now()->toDateString())
                        ->required(),

                    TextInput::make('dias_vigencia')
                        ->label('Vigencia (días)')
                        ->numeric()
                        ->default(15)
                        ->minValue(1)
                        ->required(),

                    Select::make('condiciones_pago')
                        ->label('Condiciones de pago')
                        ->options([
                            'Contado' => 'Contado',
                            'Crédito 15 días' => 'Crédito 15 días',
                            'Crédito 30 días' => 'Crédito 30 días',
                            'Crédito 60 días' => 'Crédito 60 días',
                        ])
                        ->placeholder('Seleccionar...')
                        ->columnSpan(2),

                    Textarea::make('notas')
                        ->label('Notas / Observaciones')
                        ->rows(1)
                        ->autosize()
                        ->columnSpan(2),
                ])
                ->columns(4)
                ->columnSpanFull(),

            Section::make('Productos')
                ->icon(Heroicon::OutlinedShoppingCart)
                ->schema([
                    // Buscador: no se guarda; al elegir un producto agrega (o suma) su línea.
                    Select::make('buscar_producto')
                        ->hiddenLabel()
                        ->placeholder('Buscar producto por nombre o código y presiona Enter…')
                        ->prefixIcon(Heroicon::MagnifyingGlass)
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Producto::where('empresa_id', $empresaId)
                            ->where('activo', true)
                            ->where(fn ($q) => $q->where('nombre', 'ilike', "%{$search}%")->orWhere('codigo', 'ilike', "%{$search}%"))
                            ->orderBy('nombre')
                            ->limit(15)
                            ->get()
                            ->mapWithKeys(fn (Producto $p) => [$p->id => "{$p->codigo} — {$p->nombre} · RD$ ".number_format((float) $p->precio, 2)]))
                        ->getOptionLabelUsing(fn ($value) => Producto::where('empresa_id', $empresaId)->find($value)?->nombre)
                        ->dehydrated(false)
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set, callable $get) use ($empresaId) {
                            if (! $state) {
                                return;
                            }

                            $producto = Producto::where('empresa_id', $empresaId)->where('activo', true)->find($state);
                            $set('buscar_producto', null);

                            if (! $producto) {
                                return;
                            }

                            $lineas = $get('lineas') ?? [];

                            foreach ($lineas as $clave => $linea) {
                                if ((int) ($linea['producto_id'] ?? 0) === $producto->id) {
                                    $lineas[$clave]['cantidad'] = (float) ($linea['cantidad'] ?? 0) + 1;
                                    $set('lineas', $lineas);

                                    return;
                                }
                            }

                            $lineas[(string) \Illuminate\Support\Str::uuid()] = [
                                'producto_id' => $producto->id,
                                'cantidad' => 1,
                                'precio_unitario' => $producto->precio,
                                'descuento' => 0,
                            ];
                            $set('lineas', $lineas);
                        }),

                    Repeater::make('lineas')
                        ->hiddenLabel()
                        ->table([
                            Repeater\TableColumn::make('Producto'),
                            Repeater\TableColumn::make('Cantidad')->width('120px'),
                            Repeater\TableColumn::make('Precio unitario')->width('170px'),
                            Repeater\TableColumn::make('Descuento')->width('150px'),
                            Repeater\TableColumn::make('Importe')->width('140px'),
                        ])
                        ->schema([
                            Select::make('producto_id')
                                ->hiddenLabel()
                                ->options(fn () => Producto::where('empresa_id', $empresaId)
                                    ->where('activo', true)
                                    ->pluck('nombre', 'id'))
                                ->searchable()
                                ->required()
                                ->live()
                                ->afterStateUpdated(function ($state, callable $set) use ($empresaId) {
                                    $producto = $state ? Producto::where('empresa_id', $empresaId)->find($state) : null;
                                    if ($producto) {
                                        $set('precio_unitario', $producto->precio);
                                    }
                                }),

                            TextInput::make('cantidad')
                                ->hiddenLabel()
                                ->numeric()
                                ->minValue(0.0001)
                                ->default(1)
                                ->live(onBlur: true)
                                ->required(),

                            TextInput::make('precio_unitario')
                                ->hiddenLabel()
                                ->numeric()
                                ->prefix('RD$')
                                ->minValue(0)
                                ->live(onBlur: true)
                                ->required(),

                            TextInput::make('descuento')
                                ->hiddenLabel()
                                ->numeric()
                                ->prefix('RD$')
                                ->live(onBlur: true)
                                ->default(0),

                            \Filament\Forms\Components\Placeholder::make('importe')
                                ->hiddenLabel()
                                ->content(fn (callable $get) => 'RD$ '.number_format(
                                    max(0, (float) $get('cantidad') * (float) $get('precio_unitario') - (float) $get('descuento')), 2)),
                        ])
                        ->defaultItems(0)
                        ->addable(false)
                        ->reorderable(false)
                        ->deleteAction(fn ($action) => $action->tooltip('Quitar')),

                    \Filament\Forms\Components\Placeholder::make('resumen')
                        ->hiddenLabel()
                        ->content(function (callable $get) {
                            $lineas = collect($get('lineas') ?? []);
                            if ($lineas->isEmpty()) {
                                return new \Illuminate\Support\HtmlString('<div style="text-align:center;padding:1.5rem;color:#94A3B8">Busca un producto arriba para agregarlo a la cotización.</div>');
                            }
                            $subtotal = $lineas->sum(fn ($l) => max(0, (float) ($l['cantidad'] ?? 0) * (float) ($l['precio_unitario'] ?? 0) - (float) ($l['descuento'] ?? 0)));

                            return new \Illuminate\Support\HtmlString(
                                '<div style="display:flex;justify-content:flex-end;gap:1.5rem;align-items:baseline">'
                                .'<span style="color:#64748B">'.$lineas->count().' '.($lineas->count() === 1 ? 'línea' : 'líneas').' · Subtotal (antes de ITBIS)</span>'
                                .'<strong style="font-size:1.4rem">RD$ '.number_format($subtotal, 2).'</strong></div>'
                            );
                        }),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('numero')
                    ->label('Número')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('cliente.nombre')
                    ->label('Cliente')
                    ->searchable()
                    ->placeholder('Sin cliente')
                    ->sortable(),

                TextColumn::make('fecha')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('fecha_vencimiento')
                    ->label('Vencimiento')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('DOP')
                    ->sortable(),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (EstadoCotizacion $state) => $state->etiqueta())
                    ->color(fn (EstadoCotizacion $state) => $state->color())
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label('Creada por')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('estado')
                    ->options(collect(EstadoCotizacion::cases())
                        ->mapWithKeys(fn (EstadoCotizacion $e) => [$e->value => $e->etiqueta()])
                        ->all()),

                SelectFilter::make('cliente_id')
                    ->label('Cliente')
                    ->options(fn () => Cliente::where('empresa_id', Filament::getTenant()?->id)
                        ->where('activo', true)
                        ->pluck('nombre', 'id'))
                    ->searchable(),

                Filter::make('rango')
                    ->schema([
                        DatePicker::make('desde')->label('Desde'),
                        DatePicker::make('hasta')->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['desde'] ?? null, fn (Builder $q, $d) => $q->whereDate('fecha', '>=', $d))
                            ->when($data['hasta'] ?? null, fn (Builder $q, $d) => $q->whereDate('fecha', '<=', $d));
                    }),
            ])
            ->defaultSort('fecha', 'desc')
            ->actions([
                ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            \Filament\Infolists\Components\TextEntry::make('numero')->label('Número'),
            \Filament\Infolists\Components\TextEntry::make('cliente.nombre')->label('Cliente')->placeholder('Sin cliente'),
            \Filament\Infolists\Components\TextEntry::make('fecha')->label('Fecha')->date('d/m/Y'),
            \Filament\Infolists\Components\TextEntry::make('fecha_vencimiento')->label('Vencimiento')->date('d/m/Y'),
            \Filament\Infolists\Components\TextEntry::make('condiciones_pago')->label('Condiciones de pago')->placeholder('—'),
            \Filament\Infolists\Components\TextEntry::make('estado')
                ->label('Estado')
                ->badge()
                ->formatStateUsing(fn (EstadoCotizacion $state) => $state->etiqueta())
                ->color(fn (EstadoCotizacion $state) => $state->color()),
            \Filament\Infolists\Components\TextEntry::make('subtotal')->label('Subtotal')->money('DOP'),
            \Filament\Infolists\Components\TextEntry::make('descuento')->label('Descuento')->money('DOP'),
            \Filament\Infolists\Components\TextEntry::make('itbis')->label('ITBIS')->money('DOP'),
            \Filament\Infolists\Components\TextEntry::make('total')->label('Total')->money('DOP'),
            \Filament\Infolists\Components\TextEntry::make('user.name')->label('Creada por'),
            \Filament\Infolists\Components\TextEntry::make('aprobadoPor.name')->label('Aprobada por')->placeholder('—'),
            \Filament\Infolists\Components\TextEntry::make('notas')->label('Notas')->placeholder('—')->columnSpanFull(),

            \Filament\Infolists\Components\RepeatableEntry::make('detalles')
                ->label('Detalle')
                ->schema([
                    \Filament\Infolists\Components\TextEntry::make('producto.nombre')->label('Producto'),
                    \Filament\Infolists\Components\TextEntry::make('cantidad')->label('Cantidad'),
                    \Filament\Infolists\Components\TextEntry::make('precio_unitario')->label('Precio unit.')->money('DOP'),
                    \Filament\Infolists\Components\TextEntry::make('descuento')->label('Descuento')->money('DOP'),
                    \Filament\Infolists\Components\TextEntry::make('itbis')->label('ITBIS')->money('DOP'),
                    \Filament\Infolists\Components\TextEntry::make('subtotal')->label('Subtotal')->money('DOP'),
                ])
                ->columns(6),
        ]);
    }


    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['cliente', 'user']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCotizaciones::route('/'),
            'create' => Pages\CreateCotizacion::route('/create'),
            'view' => Pages\ViewCotizacion::route('/{record}'),
            'edit' => Pages\EditCotizacion::route('/{record}/edit'),
        ];
    }
}
