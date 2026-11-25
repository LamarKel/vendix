<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MotivoMerma;
use App\Enums\OrigenMovimiento;
use App\Enums\TipoMovimiento;
use App\Exceptions\MermaInvalidaException;
use App\Exceptions\StockInsuficienteException;
use App\Models\Empresa;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mermas: mercancía que se pierde (se daña o se pudre, se vence, u otro motivo). No
 * tienen tablas propias: cada línea es un movimiento SALIDA del Kardex con origen "merma", su
 * motivo y el costo del producto en ese momento, así el Kardex sigue siendo la única fuente de
 * lo que entra y sale. Anular una merma registra el movimiento contrario; nada se borra.
 */
class MermaService
{
    public function __construct(private readonly InventarioService $inventarioService) {}

    /**
     * Registra varias mermas a la vez (p. ej. lo que se botó al cierre del día), todas o
     * ninguna.
     *
     * @param  array<int, array{producto_id: int|string|null, cantidad: int|float|string|null, motivo: MotivoMerma|string|null, nota?: string|null}>  $lineas
     * @return Collection<int, MovimientoInventario>
     *
     * @throws MermaInvalidaException
     * @throws StockInsuficienteException si alguna línea saca más de lo que hay en stock
     */
    public function registrar(Empresa $empresa, array $lineas, ?int $userId = null, ?string $observacion = null): Collection
    {
        if ($lineas === []) {
            throw new MermaInvalidaException('Agrega al menos un producto a la merma.');
        }

        $observacion = filled($observacion) ? trim($observacion) : null;

        // Los ids vienen del formulario (manipulables desde el navegador): se revalida que cada
        // producto sea de esta empresa antes de mover nada.
        $productos = Producto::query()
            ->where('empresa_id', $empresa->id)
            ->whereIn('id', collect($lineas)->pluck('producto_id')->filter()->all())
            ->get()
            ->keyBy('id');

        $validadas = [];

        foreach (array_values($lineas) as $i => $linea) {
            $numero = $i + 1;
            $producto = $productos->get((int) ($linea['producto_id'] ?? 0));

            if ($producto === null) {
                throw new MermaInvalidaException("Línea {$numero}: el producto no existe en esta empresa.");
            }

            if (! $producto->controla_stock) {
                throw new MermaInvalidaException("«{$producto->nombre}» no lleva inventario: no se le puede registrar merma.");
            }

            $cantidad = $this->cantidad($linea['cantidad'] ?? null);

            if ($cantidad === null) {
                throw new MermaInvalidaException("Línea {$numero}: la cantidad de «{$producto->nombre}» debe ser mayor que cero.");
            }

            $motivo = $linea['motivo'] ?? null;
            $motivo = $motivo instanceof MotivoMerma ? $motivo : MotivoMerma::tryFrom((string) $motivo);

            if ($motivo === null) {
                throw new MermaInvalidaException("Línea {$numero}: escoge el motivo de la merma de «{$producto->nombre}».");
            }

            $nota = filled($linea['nota'] ?? null) ? trim((string) $linea['nota']) : null;

            $validadas[] = [$producto, $cantidad, $motivo, $nota ?? $observacion];
        }

        return DB::transaction(fn () => collect($validadas)->map(
            fn (array $linea) => $this->inventarioService->registrarMovimiento(
                $linea[0],
                TipoMovimiento::SALIDA,
                OrigenMovimiento::MERMA,
                $linea[1],
                null,
                $userId,
                $linea[3],
                motivo: $linea[2],
            ),
        ));
    }

    /**
     * Anula una merma registrada a mano: devuelve la cantidad al stock con un movimiento ENTRADA
     * al mismo costo con que salió, enlazado al original. Las mermas de devoluciones de clientes
     * se corrigen desde la devolución, no desde aquí.
     *
     * @throws MermaInvalidaException
     */
    public function anular(MovimientoInventario $merma, Empresa $empresa, string $razon, ?int $userId = null): MovimientoInventario
    {
        $razon = trim($razon);

        if ($razon === '') {
            throw new MermaInvalidaException('Escribe por qué se anula la merma.');
        }

        return DB::transaction(function () use ($merma, $empresa, $razon, $userId) {
            // Bloquea la merma: dos anulaciones a la vez no pueden devolver el stock dos veces
            // (además del índice único en revierte_movimiento_id).
            $merma = MovimientoInventario::query()
                ->where('empresa_id', $empresa->id)
                ->lockForUpdate()
                ->find($merma->id);

            if ($merma === null || ! $merma->esMermaManual()) {
                throw new MermaInvalidaException('Solo se pueden anular mermas registradas desde la pantalla de Mermas.');
            }

            if ($merma->reversion()->exists()) {
                throw new MermaInvalidaException('Esta merma ya fue anulada.');
            }

            $producto = Producto::query()->where('empresa_id', $empresa->id)->find($merma->producto_id);

            if ($producto === null || ! $producto->controla_stock) {
                throw new MermaInvalidaException('El producto ya no lleva inventario: no se puede devolver la merma al stock.');
            }

            return $this->inventarioService->registrarMovimiento(
                $producto,
                TipoMovimiento::ENTRADA,
                OrigenMovimiento::MERMA,
                (string) $merma->cantidad,
                null,
                $userId,
                "Anulación de merma #{$merma->id}: {$razon}",
                motivo: $merma->motivo,
                costoUnitario: $merma->costo_unitario !== null ? (string) $merma->costo_unitario : null,
                revierteMovimientoId: $merma->id,
            );
        });
    }

    /** Cantidad > 0 con 3 decimales (libras, kilos o unidades), o null si no es válida. */
    private function cantidad(mixed $valor): ?string
    {
        if ($valor === null || $valor === '' || ! is_numeric($valor)) {
            return null;
        }

        $cantidad = bcadd(number_format((float) $valor, 3, '.', ''), '0', 3);

        return bccomp($cantidad, '0', 3) > 0 ? $cantidad : null;
    }
}
