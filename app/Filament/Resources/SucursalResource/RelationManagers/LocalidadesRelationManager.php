<?php

declare(strict_types=1);

namespace App\Filament\Resources\SucursalResource\RelationManagers;

use App\Enums\TipoLocalidad;
use App\Models\Localidad;
use App\Models\StockLocalidad;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/**
 * Localidades de la sucursal. Venta y recepción son únicas por sucursal (índice parcial en la
 * base): para cambiar cuál es la de venta, se desactiva la actual y se crea/activa otra.
 */
class LocalidadesRelationManager extends RelationManager
{
    protected static string $relationship = 'localidades';

    protected static ?string $title = 'Localidades';

    public function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema->components([
            TextInput::make('nombre')
                ->label('Nombre')
                ->placeholder('Ej.: Tienda, Almacén, Patio, Segundo piso')
                ->required()
                ->maxLength(80)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('sucursal_id', $this->getOwnerRecord()->id))
                ->validationMessages(['unique' => 'Esta sucursal ya tiene una localidad con ese nombre.']),
            Select::make('tipo')
                ->label('Tipo')
                ->options(collect(TipoLocalidad::cases())->mapWithKeys(fn (TipoLocalidad $t) => [$t->value => $t->etiqueta()]))
                ->default(TipoLocalidad::ALMACENAJE->value)
                ->required()
                ->rule(fn (?Localidad $record) => function (string $atributo, $valor, \Closure $falla) use ($record) {
                    $tipo = TipoLocalidad::tryFrom((string) $valor);
                    if ($tipo === null || ! $tipo->esUnicaPorSucursal()) {
                        return;
                    }
                    $existe = Localidad::where('sucursal_id', $this->getOwnerRecord()->id)
                        ->where('tipo', $tipo->value)->where('activa', true)
                        ->when($record, fn ($q) => $q->whereKeyNot($record->id))
                        ->exists();
                    if ($existe) {
                        $falla("La sucursal ya tiene una localidad de {$tipo->corta()} activa. Desactívala primero.");
                    }
                }),
            Toggle::make('activa')->label('Activa')->default(true)
                ->rule(fn (?Localidad $record) => function (string $atributo, $valor, \Closure $falla) use ($record) {
                    if ($record !== null && ! $valor && StockLocalidad::where('localidad_id', $record->id)->where('cantidad', '<>', 0)->exists()) {
                        $falla('No se puede desactivar una localidad que todavía tiene existencia. Muévela primero.');
                    }
                }),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('nombre')
            ->columns([
                TextColumn::make('nombre')->label('Localidad'),
                TextColumn::make('tipo')->label('Tipo')->badge()->formatStateUsing(fn (TipoLocalidad $state) => $state->corta()),
                TextColumn::make('stocks_sum_cantidad')->label('Unidades')->sum('stocks', 'cantidad')->numeric(2)->placeholder('0'),
                IconColumn::make('activa')->label('Activa')->boolean(),
            ])
            ->headerActions([
                CreateAction::make()->mutateDataUsing(fn (array $data) => [...$data, 'empresa_id' => $this->getOwnerRecord()->empresa_id]),
            ])
            ->recordActions([EditAction::make()]);
    }
}
