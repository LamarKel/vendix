<?php

declare(strict_types=1);

namespace App\Filament\Resources\ConteoResource\Pages;

use App\Enums\TipoConteo;
use App\Filament\Resources\ConteoResource;
use App\Filament\Resources\RequisicionResource;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\User;
use App\Services\ConteoService;
use App\Services\LocalidadService;
use App\Support\MensajeError;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;

class ListConteos extends ListRecords
{
    protected static string $resource = ConteoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('nuevoConteo')
                ->label('Nuevo conteo')
                ->icon('heroicon-o-plus')
                ->visible(fn () => auth()->user()?->can('conteos.crear'))
                ->modalHeading('Nuevo conteo físico')
                ->modalDescription('El contador no verá lo que dice el sistema. Al terminar, revisas las diferencias y apruebas el ajuste.')
                ->modalWidth('3xl')
                ->schema([
                    Select::make('localidad_id')->label('Localidad a contar')
                        ->options(fn () => RequisicionResource::opcionesLocalidades(soloMiSucursal: true))->required()->native(false),
                    Select::make('tipo')->label('Tipo')
                        ->options(collect(TipoConteo::cases())->mapWithKeys(fn (TipoConteo $t) => [$t->value => $t->etiqueta()]))
                        ->default(TipoConteo::CICLICO->value)->required()->live(),
                    TextInput::make('cantidad_ciclico')->label('¿Cuántos productos propone el sistema?')->numeric()->integer()
                        ->default(15)->minValue(1)->maxValue(200)
                        ->visible(fn (Get $get) => $get('tipo') === TipoConteo::CICLICO->value),
                    Select::make('categoria_id')->label('Categoría (opcional)')
                        ->options(fn () => Categoria::where('empresa_id', Filament::getTenant()->id)->orderBy('nombre')->pluck('nombre', 'id'))
                        ->visible(fn (Get $get) => $get('tipo') === TipoConteo::SELECCION->value),
                    Select::make('producto_ids')->label('Productos')->multiple()->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Producto::where('empresa_id', Filament::getTenant()->id)
                            ->where('controla_stock', true)->where('activo', true)
                            ->where(fn ($q) => $q->whereLikeSinAcentos('nombre', $search)->orWhere('codigo', 'ilike', "%{$search}%"))
                            ->orderBy('nombre')->limit(30)->pluck('nombre', 'id')->all())
                        ->getOptionLabelsUsing(fn (array $values) => Producto::where('empresa_id', Filament::getTenant()->id)->whereIn('id', $values)->pluck('nombre', 'id')->all())
                        ->visible(fn (Get $get) => $get('tipo') === TipoConteo::SELECCION->value),
                    Select::make('asignado_a')->label('¿Quién cuenta?')
                        ->options(fn () => User::where('empresa_id', Filament::getTenant()->id)
                            ->when(RequisicionResource::sucursalUsuario(), fn ($q, $s) => $q->where(fn ($w) => $w->where('sucursal_id', $s)->orWhereNull('sucursal_id')))
                            ->orderBy('name')->pluck('name', 'id'))
                        ->required()->searchable(),
                    Textarea::make('notas')->label('Notas')->maxLength(500)->rows(2),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        $empresa = Filament::getTenant();
                        $tipo = TipoConteo::from($data['tipo']);
                        $productos = match ($tipo) {
                            TipoConteo::CICLICO => app(ConteoService::class)->sugerirCiclico(
                                $empresa,
                                app(LocalidadService::class)->localidadDeEmpresa($empresa, (int) $data['localidad_id']),
                                (int) ($data['cantidad_ciclico'] ?? 15),
                            ),
                            TipoConteo::SELECCION => array_unique([
                                ...array_map('intval', $data['producto_ids'] ?? []),
                                ...(filled($data['categoria_id'] ?? null)
                                    ? Producto::where('empresa_id', $empresa->id)->where('categoria_id', $data['categoria_id'])->where('controla_stock', true)->where('activo', true)->pluck('id')->all()
                                    : []),
                            ]),
                            TipoConteo::TOTAL => [],
                        };

                        if ($tipo === TipoConteo::CICLICO && $productos === []) {
                            throw new \RuntimeException('No hay productos para proponer: todo lo de esa localidad se contó en los últimos 30 días o no tiene existencia.');
                        }

                        $c = app(ConteoService::class)->crear($empresa, auth()->user(), (int) $data['localidad_id'], $tipo, (int) $data['asignado_a'], $productos, $data['notas'] ?? null);
                    } catch (\Throwable $e) {
                        Notification::make()->title(MensajeError::para($e))->danger()->send();
                        $action->halt();

                        return;
                    }

                    Notification::make()->title("Conteo {$c->etiquetaNumero()} creado")->body($c->detalles()->count().' productos por contar.')->success()->send();
                    $this->redirect(ConteoResource::getUrl('view', ['record' => $c]));
                }),
        ];
    }
}
