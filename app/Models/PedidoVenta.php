<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoPedidoVenta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PedidoVenta extends Model
{
    protected $table = 'pedidos_venta';

    protected $fillable = [
        'empresa_id', 'numero', 'nombre_cliente', 'cliente_id', 'cotizacion_id', 'vendedor_id', 'estado', 'notas',
        'total_estimado', 'venta_id', 'cobrado_por', 'cobrado_en', 'cancelado_por', 'cancelado_en',
        'motivo_cancelacion',
    ];

    protected $casts = [
        'numero' => 'integer',
        'estado' => EstadoPedidoVenta::class,
        'total_estimado' => 'decimal:2',
        'cobrado_en' => 'datetime',
        'cancelado_en' => 'datetime',
    ];

    protected $attributes = [
        'estado' => 'pendiente',
        'total_estimado' => 0,
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendedor_id');
    }

    public function cobradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cobrado_por');
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetallePedidoVenta::class);
    }

    public function scopePendientes(Builder $query): Builder
    {
        return $query->where('estado', EstadoPedidoVenta::PENDIENTE->value);
    }

    public function estaPendiente(): bool
    {
        return $this->estado === EstadoPedidoVenta::PENDIENTE;
    }

    public function etiquetaNumero(): string
    {
        return 'P-'.str_pad((string) $this->numero, 4, '0', STR_PAD_LEFT);
    }

    public function cotizacion(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Cotizacion::class);
    }
}
