<?php

declare(strict_types=1);

namespace App\Filament\Resources\ConteoResource\Pages;

use App\Enums\EstadoConteo;
use App\Filament\Resources\ConteoResource;
use App\Models\Conteo;
use App\Models\User;
use App\Services\ConteoService;
use App\Services\RequisicionService;
use App\Support\MensajeError;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewConteo extends ViewRecord
{
    protected static string $resource = ConteoResource::class;

    private function c(): Conteo
    {
        return $this->record->loadMissing(['detalles.producto', 'localidad']);
    }

    private function esContador(): bool
    {
        $u = auth()->user();

        return $u !== null && ((int) $this->c()->asignado_a === (int) $u->id || $u->can('sucursales.administrar'));
    }

    private function esEncargado(): bool
    {
        $u = auth()->user();

        return $u !== null && $u->can('conteos.aprobar') && app(RequisicionService::class)->esDeSuSucursal($u, $this->c()->localidad);
    }

    private function ejecutar(Action $action, \Closure $paso, string $exito): void
    {
        try {
            $paso(app(ConteoService::class), Filament::getTenant(), auth()->user());
        } catch (\Throwable $e) {
            Notification::make()->title(MensajeError::para($e))->danger()->send();
            $action->halt();

            return;
        }

        Notification::make()->title($exito)->success()->send();
        $this->record->refresh();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('contar')->label('Contar')->icon('heroicon-o-pencil-square')->color('primary')
                ->visible(fn () => $this->c()->estado === EstadoConteo::EN_CONTEO && $this->esContador())
                ->modalHeading('Anota lo que cuentas')
                ->modalDescription('Cuenta lo que ves. Deja en blanco lo que todavía no has contado; si no hay, pon 0.')
                ->modalWidth('3xl')
                ->schema([
                    Repeater::make('lineas')->hiddenLabel()
                        ->table([TableColumn::make('Producto'), TableColumn::make('Cantidad contada')])
                        ->schema([
                            Hidden::make('detalle_id'),
                            TextInput::make('producto')->hiddenLabel()->disabled()->dehydrated(false),
                            TextInput::make('cantidad')->hiddenLabel()->numeric()->minValue(0)->step(0.001),
                        ])
                        ->default(fn () => $this->c()->detalles->sortBy('producto.nombre')->map(fn ($d) => [
                            'detalle_id' => $d->id,
                            'producto' => "{$d->producto->codigo} — {$d->producto->nombre} ({$d->producto->unidad_base})",
                            'cantidad' => $d->cantidad_contada !== null ? (string) (float) $d->cantidad_contada : null,
                        ])->values()->all())
                        ->addable(false)->deletable(false)->reorderable(false)->columnSpanFull(),
                ])
                ->action(function (array $data, Action $action) {
                    $cantidades = collect($data['lineas'] ?? [])->mapWithKeys(fn ($l) => [(int) $l['detalle_id'] => $l['cantidad'] ?? null])->all();
                    $this->ejecutar($action, fn ($svc, $emp, $u) => $svc->registrar($this->c(), $emp, $u, $cantidades), 'Conteo guardado');
                }),

            Action::make('cerrar')->label('Terminé de contar')->icon('heroicon-o-check-circle')->color('success')
                ->visible(fn () => $this->c()->estado === EstadoConteo::EN_CONTEO && $this->esContador())
                ->requiresConfirmation()
                ->modalDescription('Después de cerrar no puedes cambiar lo contado, salvo que el encargado pida reconteo.')
                ->action(fn (Action $action) => $this->ejecutar($action, fn ($svc, $emp, $u) => $svc->cerrar($this->c(), $emp, $u), 'Conteo enviado a revisión')),

            Action::make('reconteo')->label('Pedir reconteo')->icon('heroicon-o-arrow-path')->color('warning')
                ->visible(fn () => $this->c()->estado === EstadoConteo::EN_REVISION && $this->esEncargado())
                ->modalWidth('2xl')
                ->schema([
                    CheckboxList::make('detalles')->label('Productos a recontar')
                        ->options(fn () => $this->c()->detalles
                            ->filter(fn ($d) => (float) $d->diferencia() !== 0.0)
                            ->mapWithKeys(fn ($d) => [$d->id => "{$d->producto->nombre} (diferencia ".rtrim(rtrim((string) $d->diferencia(), '0'), '.').')'])
                            ->all() ?: $this->c()->detalles->mapWithKeys(fn ($d) => [$d->id => $d->producto->nombre])->all())
                        ->required()->columns(1),
                    Select::make('reasignar_a')->label('Que lo cuente (recomendado: otra persona)')
                        ->options(fn () => User::where('empresa_id', Filament::getTenant()->id)->orderBy('name')->pluck('name', 'id'))
                        ->searchable(),
                ])
                ->action(fn (array $data, Action $action) => $this->ejecutar($action, fn ($svc, $emp, $u) => $svc->pedirReconteo(
                    $this->c(), $emp, $u, array_map('intval', $data['detalles'] ?? []), filled($data['reasignar_a'] ?? null) ? (int) $data['reasignar_a'] : null,
                ), 'Reconteo pedido')),

            Action::make('aprobar')->label('Aprobar y ajustar')->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn () => $this->c()->estado === EstadoConteo::EN_REVISION && $this->esEncargado())
                ->requiresConfirmation()
                ->modalDescription(fn () => 'Se ajustará la existencia de '.$this->c()->localidad->nombre.' por las diferencias (RD$ '.number_format((float) $this->c()->valor_diferencia, 2).'). Queda en el kardex con tu nombre.')
                ->action(fn (Action $action) => $this->ejecutar($action, fn ($svc, $emp, $u) => $svc->aprobar($this->c(), $emp, $u), 'Conteo aprobado: inventario ajustado')),

            Action::make('cancelar')->label('Cancelar')->icon('heroicon-o-no-symbol')->color('gray')
                ->visible(fn () => in_array($this->c()->estado, [EstadoConteo::EN_CONTEO, EstadoConteo::EN_REVISION], true) && auth()->user()?->can('conteos.crear'))
                ->schema([TextInput::make('motivo')->label('Motivo')->required()->maxLength(255)])
                ->action(fn (array $data, Action $action) => $this->ejecutar($action, fn ($svc, $emp, $u) => $svc->cancelar($this->c(), $emp, $u, $data['motivo']), 'Conteo cancelado')),
        ];
    }
}
