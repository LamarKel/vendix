<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Modulo;
use App\Services\PedidoVentaService;
use App\Support\MensajeError;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Pantalla del vendedor de piso: busca productos, arma el pedido y lo envía a caja a nombre del
 * cliente. Reutiliza la búsqueda y el carrito del POS (precio y factor saneados desde la base),
 * pero no cobra ni necesita caja abierta.
 */
class TomarPedido extends PuntoDeVenta
{
    protected string $view = 'filament.pages.tomar-pedido';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Tomar pedido';

    protected static ?string $title = 'Tomar pedido';

    protected static ?string $slug = 'tomar-pedido';

    public string $nombreCliente = '';

    public string $notasPedido = '';

    public static function modulo(): Modulo
    {
        return Modulo::VENTAS_PEDIDOS;
    }

    public static function puedeAccederPorPermiso(): bool
    {
        return auth()->user()?->can('pedidos.crear') ?? false;
    }

    /** El vendedor de piso no aplica descuentos: eso se decide en caja. */
    public function puedeAplicarDescuento(): bool
    {
        return false;
    }

    /** Aquí no se cobra: el cobro es en caja. */
    public function cobrar(): void
    {
        Notification::make()->title('Los pedidos se cobran en caja.')->warning()->send();
    }

    public function enviarACaja(): void
    {
        if (! auth()->user()?->can('pedidos.crear')) {
            Notification::make()->title('No tienes permiso para tomar pedidos.')->danger()->send();

            return;
        }

        $this->sanearCarrito();

        try {
            $pedido = app(PedidoVentaService::class)->crear(
                empresa: $this->empresa(),
                vendedor: auth()->user(),
                nombreCliente: $this->nombreCliente,
                lineas: collect($this->carrito)->map(fn (array $l) => [
                    'producto_id' => (int) $l['producto_id'],
                    'presentacion_id' => $l['presentacion_id'] ?? null,
                    'cantidad' => $l['cantidad'],
                ])->all(),
                clienteId: $this->clienteId,
                notas: $this->notasPedido,
            );
        } catch (\Throwable $e) {
            Notification::make()->title(MensajeError::para($e))->danger()->send();

            return;
        }

        Notification::make()
            ->title("Pedido {$pedido->etiquetaNumero()} enviado a caja")
            ->body("A nombre de {$pedido->nombre_cliente}. Dile al cliente que pase por caja.")
            ->success()
            ->send();

        $this->carrito = [];
        $this->nombreCliente = '';
        $this->notasPedido = '';
        $this->busquedaProducto = '';
        $this->quitarCliente();
        $this->recalcularTotales();
    }
}
