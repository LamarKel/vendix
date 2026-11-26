<?php

declare(strict_types=1);

namespace App\Filament\Resources\CotizacionResource\Pages;

use App\Enums\EstadoCotizacion;
use App\Enums\FormaPago;
use App\Enums\TipoComprobante;
use App\Enums\TipoPago;
use App\Filament\Resources\CotizacionResource;
use App\Models\Cotizacion;
use App\Services\CotizacionService;
use App\Services\SecuenciaNcfService;
use App\Support\MensajeError;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use RuntimeException;
use Throwable;

class ViewCotizacion extends ViewRecord
{
    protected static string $resource = CotizacionResource::class;

    protected function getHeaderActions(): array
    {
        $user = auth()->user();

        return [
            EditAction::make()
                ->visible(fn (Cotizacion $record) => $record->estado->puedeEditar() && $user->can('cotizaciones.editar')),

            Action::make('enviar')
                ->label('Marcar como Enviada')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('info')
                ->visible(fn (Cotizacion $record) => $record->estado === EstadoCotizacion::BORRADOR)
                ->action(function (Cotizacion $record) {
                    $record->update(['estado' => EstadoCotizacion::ENVIADA]);
                    Notification::make()->title('Cotización marcada como enviada.')->success()->send();
                }),

            Action::make('aprobar')
                ->label('Aprobar')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->visible(fn (Cotizacion $record) => in_array($record->estado, [EstadoCotizacion::BORRADOR, EstadoCotizacion::ENVIADA]) && $user->can('cotizaciones.aprobar'))
                ->action(function (Cotizacion $record) {
                    $record->update([
                        'estado' => EstadoCotizacion::APROBADA,
                        'aprobado_por' => auth()->id(),
                        'aprobado_en' => now(),
                    ]);
                    Notification::make()->title('Cotización aprobada.')->success()->send();
                }),

            Action::make('rechazar')
                ->label('Rechazar')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->visible(fn (Cotizacion $record) => in_array($record->estado, [EstadoCotizacion::BORRADOR, EstadoCotizacion::ENVIADA, EstadoCotizacion::APROBADA]))
                ->requiresConfirmation()
                ->modalHeading('¿Rechazar esta cotización?')
                ->action(function (Cotizacion $record) {
                    $record->update(['estado' => EstadoCotizacion::RECHAZADA]);
                    Notification::make()->title('Cotización rechazada.')->warning()->send();
                }),

            // Flujo normal: la cotización aprobada va a caja como pedido en espera y la cajera la
            // cobra en su turno (queda en su arqueo), al precio cotizado.
            Action::make('enviarACaja')
                ->label(fn (Cotizacion $record) => $this->pedidoEnCaja($record) ? 'En caja: '.$this->pedidoEnCaja($record)->etiquetaNumero() : 'Enviar a caja')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('success')
                ->disabled(fn (Cotizacion $record) => $this->pedidoEnCaja($record) !== null)
                ->visible(fn (Cotizacion $record) => $record->puedeConvertirse() && $user->can('cotizaciones.facturar'))
                ->requiresConfirmation()
                ->modalHeading('¿Enviar a caja?')
                ->modalDescription(fn (Cotizacion $record) => 'Queda como pedido en espera a nombre de '.($record->cliente?->nombre ?? 'cliente general')
                    .'. La caja lo cobra al precio cotizado (RD$ '.number_format((float) $record->total, 2).') mientras esté vigente.')
                ->modalSubmitActionLabel('Enviar a caja')
                ->action(function (Cotizacion $record) {
                    try {
                        $pedido = app(CotizacionService::class)->enviarACaja($record, Filament::getTenant(), auth()->user());
                        Notification::make()
                            ->title("Enviada a caja como pedido {$pedido->etiquetaNumero()}")
                            ->body('La cajera la encuentra en PEDIDOS EN ESPERA por el nombre del cliente.')
                            ->success()
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()->title('No se pudo enviar a caja')->body(MensajeError::para($e))->danger()->send();
                    }
                }),

            // Facturar directo (sin pasar por caja): solo para quien administra sucursales, p. ej.
            // una factura a crédito hecha desde la oficina. No queda en ningún arqueo de caja.
            Action::make('facturar')
                ->label('Facturar directo')
                ->icon(Heroicon::OutlinedShoppingCart)
                ->color('gray')
                ->visible(fn (Cotizacion $record) => $record->puedeConvertirse() && $user->can('cotizaciones.facturar') && $user->can('sucursales.administrar') && $this->pedidoEnCaja($record) === null)
                ->form([
                    Select::make('tipo_comprobante')
                        ->label('Tipo de Comprobante')
                        // Solo los que el POS ofrecería: con secuencia disponible y del régimen de
                        // la empresa (físico o e-CF). Sin notas de crédito/débito.
                        ->options(fn () => collect(app(SecuenciaNcfService::class)->tiposDisponibles(Filament::getTenant()))
                            ->filter(fn (TipoComprobante $t) => $t->esDeVenta() && ! $t->esNotaCredito() && ! $t->esNotaDebito())
                            ->mapWithKeys(fn (TipoComprobante $t) => [$t->value => "{$t->value} — {$t->etiqueta()}"])
                            ->all())
                        ->default(fn (Cotizacion $record) => $this->tipoSugerido($record))
                        ->helperText(fn (Cotizacion $record) => $record->cliente?->documento
                            ? null
                            : 'La cotización no tiene cliente con RNC/cédula: solo aplica factura de consumo.')
                        ->required(),

                    Select::make('forma_pago')
                        ->label('Forma de Pago')
                        ->options(collect(FormaPago::cases())
                            ->mapWithKeys(fn (FormaPago $f) => [$f->value => $f->etiqueta()])
                            ->all())
                        ->default(FormaPago::EFECTIVO->value)
                        ->required(),

                    Select::make('tipo_pago')
                        ->label('Tipo de Pago')
                        ->options(collect(TipoPago::cases())
                            ->mapWithKeys(fn (TipoPago $t) => [$t->value => $t->etiqueta()])
                            ->all())
                        ->default(TipoPago::CONTADO->value)
                        ->required(),
                ])
                ->action(function (Cotizacion $record, array $data) {
                    try {
                        $tipoComprobante = TipoComprobante::from($data['tipo_comprobante']);
                        $formaPago = FormaPago::from($data['forma_pago']);
                        $tipoPago = TipoPago::from((int) $data['tipo_pago']);

                        $venta = app(CotizacionService::class)->convertirAVenta(
                            $record,
                            Filament::getTenant(),
                            [
                                'tipo_comprobante' => $tipoComprobante,
                                'forma_pago' => $formaPago,
                                'tipo_pago' => $tipoPago,
                            ],
                        );

                        Notification::make()
                            ->title("Factura {$venta->ncf} creada")
                            ->body('RD$ '.number_format((float) $venta->total, 2))
                            ->success()
                            ->persistent()
                            ->actions([
                                Action::make('imprimirFactura')->label('Imprimir factura')->button()
                                    ->url(route('ventas.pdf', $venta), shouldOpenInNewTab: true),
                                Action::make('imprimirTicket')->label('Ticket')->link()
                                    ->url(route('ventas.ticket', $venta), shouldOpenInNewTab: true),
                            ])
                            ->send();

                        $this->refreshFormData(['estado', 'venta_id']);
                    } catch (Throwable $e) {
                        // Cualquier fallo (secuencia agotada, stock, cliente sin RNC...) sale como
                        // aviso legible; los inesperados quedan en el log con referencia.
                        Notification::make()->title('No se pudo facturar')->body(MensajeError::para($e))->danger()->persistent()->send();
                    }
                }),

            Action::make('duplicar')
                ->label('Duplicar')
                ->icon(Heroicon::OutlinedDocumentDuplicate)
                ->color('gray')
                ->visible(fn () => $user->can('cotizaciones.crear'))
                ->requiresConfirmation()
                ->modalHeading('¿Duplicar esta cotización?')
                ->modalDescription('Se creará una nueva cotización en borrador con los mismos productos y precios.')
                ->action(function (Cotizacion $record) {
                    try {
                        $nueva = app(CotizacionService::class)->duplicar($record, Filament::getTenant());
                        Notification::make()->title("Cotización {$nueva->numero} creada.")->success()->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->title(MensajeError::para($e))->danger()->send();
                    }
                }),

            Action::make('exportarPdf')
                ->label('PDF')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('gray')
                ->visible(fn () => $user->can('cotizaciones.exportar'))
                ->url(fn (Cotizacion $record) => route('cotizaciones.pdf', $record), shouldOpenInNewTab: true),
        ];
    }

    /** Pedido pendiente en caja que salió de esta cotización (si lo hay). */
    private function pedidoEnCaja(Cotizacion $record): ?\App\Models\PedidoVenta
    {
        return once(fn () => \App\Models\PedidoVenta::where('empresa_id', $record->empresa_id)
            ->where('cotizacion_id', $record->id)
            ->where('estado', \App\Enums\EstadoPedidoVenta::PENDIENTE->value)
            ->first());
    }

    /** B01/31 si el cliente tiene documento; si no, consumo (B02/32), según lo disponible. */
    private function tipoSugerido(Cotizacion $record): ?string
    {
        $disponibles = collect(app(SecuenciaNcfService::class)->tiposDisponibles(Filament::getTenant()));
        $preferidos = $record->cliente?->documento
            ? [TipoComprobante::FACTURA_CREDITO_FISCAL, TipoComprobante::FACTURA_CREDITO_FISCAL_FISICA, TipoComprobante::FACTURA_CONSUMO, TipoComprobante::FACTURA_CONSUMO_FISICA]
            : [TipoComprobante::FACTURA_CONSUMO, TipoComprobante::FACTURA_CONSUMO_FISICA];

        foreach ($preferidos as $tipo) {
            if ($disponibles->contains($tipo)) {
                return $tipo->value;
            }
        }

        return null;
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Recién creada: se abre el modal para imprimirla (ver CreateCotizacion).
        if (session()->pull('cotizacion_recien_creada') === $this->getRecord()->getKey()) {
            $this->defaultAction = 'imprimirTrasCrear';
        }
    }

    public function imprimirTrasCrearAction(): Action
    {
        return Action::make('imprimirTrasCrear')
            ->modalHeading(fn () => "Cotización {$this->getRecord()->numero} creada")
            ->modalDescription(fn () => 'Total RD$ '.number_format((float) $this->getRecord()->total, 2).'. ¿Quieres imprimirla o descargar el PDF?')
            ->modalIcon(Heroicon::OutlinedPrinter)
            ->modalIconColor('success')
            ->modalWidth('md')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Ahora no')
            ->extraModalFooterActions(fn () => [
                Action::make('abrirPdfCotizacion')
                    ->label('Imprimir / PDF')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->url(route('cotizaciones.pdf', $this->getRecord()), shouldOpenInNewTab: true)
                    ->close(),
            ]);
    }
}
