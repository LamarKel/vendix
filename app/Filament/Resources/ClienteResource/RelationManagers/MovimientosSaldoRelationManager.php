<?php

namespace App\Filament\Resources\ClienteResource\RelationManagers;

use App\Enums\TipoMovimientoSaldo;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MovimientosSaldoRelationManager extends RelationManager
{
    protected static string $relationship = 'movimientosSaldo';

    protected static ?string $title = 'Movimientos de saldo a favor';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y h:i A')
                    ->sortable(),

                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->formatStateUsing(fn (TipoMovimientoSaldo $state) => $state->etiqueta())
                    ->badge()
                    ->color(fn (TipoMovimientoSaldo $state) => $state->color()),

                TextColumn::make('monto')
                    ->label('Monto')
                    ->money('DOP')
                    ->sortable(),

                TextColumn::make('saldo_anterior')
                    ->label('Saldo anterior')
                    ->money('DOP'),

                TextColumn::make('saldo_posterior')
                    ->label('Saldo posterior')
                    ->money('DOP'),

                TextColumn::make('concepto')
                    ->label('Concepto')
                    ->wrap()
                    ->limit(60),

                TextColumn::make('venta.numero')
                    ->label('Venta')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('usuario.name')
                    ->label('Usuario')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options(
                        collect(TipoMovimientoSaldo::cases())
                            ->mapWithKeys(fn (TipoMovimientoSaldo $t) => [$t->value => $t->etiqueta()])
                            ->all()
                    ),
            ]);
    }
}
