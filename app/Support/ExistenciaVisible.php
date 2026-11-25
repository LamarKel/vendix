<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Empresa;
use App\Models\Localidad;
use App\Models\Producto;
use App\Models\StockLocalidad;
use App\Models\User;
use App\Services\LocalidadService;

/**
 * Regla de visibilidad del inventario por sucursal:
 * - De SU sucursal, el usuario ve la existencia por localidad.
 * - De las demás sucursales, solo el total de cada una.
 * - Quien administra sucursales ve el detalle de todas.
 */
class ExistenciaVisible
{
    /**
     * @return array{propia: array<int, array{localidad: string, tipo: string, cantidad: float}>, otras: array<int, array{sucursal: string, total: float}>, sucursal: string}
     */
    public static function paraProducto(Producto $producto, User $usuario, Empresa $empresa): array
    {
        $admin = $usuario->can('sucursales.administrar');
        $miSucursal = app(LocalidadService::class)->sucursalDe($empresa, $usuario);

        $filas = StockLocalidad::query()
            ->where('stock_localidades.empresa_id', $empresa->id)
            ->where('producto_id', $producto->id)
            ->with('localidad.sucursal')
            ->get();

        $localidadesPropias = Localidad::where('empresa_id', $empresa->id)
            ->where('activa', true)
            ->when(! $admin, fn ($q) => $q->where('sucursal_id', $miSucursal->id))
            ->with('sucursal')
            ->orderBy('sucursal_id')->orderBy('nombre')
            ->get();

        $propia = $localidadesPropias->map(fn (Localidad $l) => [
            'localidad' => $admin ? $l->nombreCompleto() : $l->nombre,
            'tipo' => $l->tipo->corta(),
            'cantidad' => (float) ($filas->firstWhere('localidad_id', $l->id)?->cantidad ?? 0),
        ])->values()->all();

        $otras = $admin ? [] : $filas
            ->filter(fn (StockLocalidad $f) => $f->localidad->sucursal_id !== $miSucursal->id)
            ->groupBy(fn (StockLocalidad $f) => $f->localidad->sucursal->nombre)
            ->map(fn ($grupo, $nombre) => ['sucursal' => $nombre, 'total' => (float) $grupo->sum('cantidad')])
            ->values()->all();

        return ['propia' => $propia, 'otras' => $otras, 'sucursal' => $admin ? 'Todas las sucursales' : $miSucursal->nombre];
    }
}
