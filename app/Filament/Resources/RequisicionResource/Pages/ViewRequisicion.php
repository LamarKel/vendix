<?php

declare(strict_types=1);

namespace App\Filament\Resources\RequisicionResource\Pages;

use App\Enums\EstadoRequisicion;
use App\Filament\Resources\RequisicionResource;
use App\Models\Requisicion;
use App\Services\RequisicionService;
use App\Support\MensajeError;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * Detalle de la requisición y sus pasos. Cada botón solo aparece a quien le toca (permiso +
 * sucursal + estado); el servicio vuelve a validar todo.
 */
class ViewRequisicion extends ViewRecord
{
    protected static string $resource = RequisicionResource::class;

    private function req(): Requisicion
    {
        return $this->record->loadMissing(['detalles.producto', 'localidadOrigen', 'localidadDestino']);
    }

    private function puede(string $permiso, ?\App\Models\Localidad $localidad = null): bool
    {
        $u = auth()->user();

        return $u !== null && $u->can($permiso)
            && ($localidad === null || app(RequisicionService::class)->esDeSuSucursal($u, $localidad));
    }

    private function ejecutar(Action $action, \Closure $paso, string $exito): void
    {
        try {
            $paso(app(RequisicionService::class), Filament::getTenant(), auth()->user());
        } catch (\Throwable $e) {
            Notification::make()->title(MensajeError::para($e))->danger()->send();
            $action->halt();

            return;
        }

        Notification::make()->title($exito)->success()->send();
        $this->record->refresh();
    }

    /** Repeater fijo con una fila por línea: cantidad editable + nota. */
    private function lineas(string $etiquetaCantidad, string $campoMaximo): Repeater
    {
        return Repeater::make('lineas')
            ->hiddenLabel()
            ->table([
                TableColumn::make('Producto'),
                TableColumn::make($etiquetaCantidad),
                TableColumn::make('Nota (obligatoria si es menos)'),
            ])
            ->schema([
                Hidden::make('detalle_id'),
                TextInput::make('producto')->hiddenLabel()->disabled()->dehydrated(false),
                TextInput::make('cantidad')->hiddenLabel()->numeric()->required()->minValue(0)->step(0.001),
                TextInput::make('nota')->hiddenLabel()->maxLength(255),
            ])
            ->default(fn () => $this->req()->detalles->map(fn ($d) => [
                'detalle_id' => $d->id,
                'producto' => $d->producto->nombre.' (máx. '.rtrim(rtrim((string) $d->{$campoMaximo}, '0'), '.').')',
                'cantidad' => (string) (float) $d->{$campoMaximo},
                'nota' => null,
            ])->all())
            ->addable(false)->deletable(false)->reorderable(false)
            ->columnSpanFull();
    }

    /** @return array{0: array<int, string>, 1: array<int, ?string>} */
    private function separar(array $data): array
    {
        $cantidades = [];
        $notas = [];
        foreach ($data['lineas'] ?? [] as $l) {
            $cantidades[(int) $l['detalle_id']] = (string) $l['cantidad'];
            $notas[(int) $l['detalle_id']] = $l['nota'] ?? null;
        }

        return [$cantidades, $notas];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('aprobar')->label('Aprobar')->icon('heroicon-o-check')->color('success')
                ->visible(fn () => $this->req()->estado === EstadoRequisicion::PENDIENTE_APROBACION && $this->puede('requisiciones.aprobar'))
                ->requiresConfirmation()
                ->action(fn (Action $action) => $this->ejecutar($action, fn ($svc, $emp, $u) => $svc->aprobar($this->req(), $emp, $u), 'Requisición aprobada')),

            Action::make('rechazar')->label('Rechazar')->icon('heroicon-o-x-mark')->color('danger')
                ->visible(fn () => $this->req()->estado === EstadoRequisicion::PENDIENTE_APROBACION && $this->puede('requisiciones.aprobar'))
                ->schema([TextInput::make('motivo')->label('Motivo')->required()->maxLength(255)])
                ->action(fn (array $data, Action $action) => $this->ejecutar($action, fn ($svc, $emp, $u) => $svc->rechazar($this->req(), $emp, $u, $data['motivo']), 'Requisición rechazada')),

            Action::make('despachar')->label('Despachar')->icon('heroicon-o-truck')->color('primary')
                ->visible(fn () => $this->req()->estado === EstadoRequisicion::PENDIENTE && $this->puede('requisiciones.despachar', $this->req()->localidadOrigen))
                ->modalHeading('Despachar requisición')
                ->modalDescription('Sale de tu localidad lo que indiques. Si mandas menos de lo pedido, explica por qué.')
                ->modalWidth('4xl')
                ->schema([$this->lineas('Despachar', 'cantidad_solicitada')])
                ->action(function (array $data, Action $action) {
                    [$c, $n] = $this->separar($data);
                    $this->ejecutar($action, fn ($svc, $emp, $u) => $svc->despachar($this->req(), $emp, $u, $c, $n), 'Despachada: queda en tránsito hasta que la reciban');
                }),

            Action::make('recibir')->label('Recibir')->icon('heroicon-o-inbox-arrow-down')->color('success')
                ->visible(fn () => $this->req()->estado === EstadoRequisicion::DESPACHADA && $this->puede('requisiciones.recibir', $this->req()->localidadDestino))
                ->modalHeading('Recibir mercancía')
                ->modalDescription('Confirma lo que de verdad llegó. Lo que falte queda registrado como faltante.')
                ->modalWidth('4xl')
                ->schema([$this->lineas('Recibido', 'cantidad_despachada')])
                ->action(function (array $data, Action $action) {
                    [$c, $n] = $this->separar($data);
                    $this->ejecutar($action, fn ($svc, $emp, $u) => $svc->recibir($this->req(), $emp, $u, $c, $n), 'Mercancía recibida');
                }),

            Action::make('cancelar')->label('Cancelar')->icon('heroicon-o-no-symbol')->color('gray')
                ->visible(fn () => in_array($this->req()->estado, [EstadoRequisicion::PENDIENTE_APROBACION, EstadoRequisicion::PENDIENTE], true)
                    && ($this->puede('requisiciones.crear', $this->req()->localidadDestino) || $this->puede('requisiciones.despachar', $this->req()->localidadOrigen)))
                ->schema([TextInput::make('motivo')->label('Motivo')->required()->maxLength(255)])
                ->action(fn (array $data, Action $action) => $this->ejecutar($action, fn ($svc, $emp, $u) => $svc->cancelar($this->req(), $emp, $u, $data['motivo']), 'Requisición cancelada')),
        ];
    }
}
