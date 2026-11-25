<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Modulo;
use App\Filament\Concerns\RestringidoPorModulo;
use App\Filament\Resources\SucursalResource\Pages;
use App\Filament\Resources\SucursalResource\RelationManagers\LocalidadesRelationManager;
use App\Models\Sucursal;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SucursalResource extends Resource
{
    use RestringidoPorModulo;

    protected static ?string $model = Sucursal::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Sucursales y localidades';

    protected static ?string $modelLabel = 'Sucursal';

    protected static ?string $pluralModelLabel = 'Sucursales';

    protected static ?string $slug = 'sucursales';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 2;

    public static function modulo(): Modulo
    {
        return Modulo::INVENTARIO_LOCALIDADES;
    }

    /** Scope explícito por empresa (no depender solo del middleware de tenancy del panel). */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->where('sucursales.empresa_id', Filament::getTenant()?->id);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nombre')
                ->label('Nombre')
                ->required()
                ->maxLength(80)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('empresa_id', Filament::getTenant()->id))
                ->validationMessages(['unique' => 'Ya existe una sucursal con este nombre.']),
            TextInput::make('codigo')->label('Código')->maxLength(20),
            TextInput::make('direccion')->label('Dirección')->maxLength(255)->columnSpanFull(),
            TextInput::make('telefono')->label('Teléfono')->maxLength(30),
            Toggle::make('activa')->label('Activa')->default(true)
                ->disabled(fn (?Sucursal $record) => $record?->es_principal)
                ->helperText('La sucursal principal no se puede desactivar.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')->label('Sucursal')->searchable(),
                TextColumn::make('codigo')->label('Código')->placeholder('—'),
                TextColumn::make('localidades_count')->label('Localidades')->counts('localidades'),
                IconColumn::make('es_principal')->label('Principal')->boolean(),
                IconColumn::make('activa')->label('Activa')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [LocalidadesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSucursales::route('/'),
            'create' => Pages\CreateSucursal::route('/create'),
            'edit' => Pages\EditSucursal::route('/{record}/edit'),
        ];
    }
}
