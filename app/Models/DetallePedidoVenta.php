<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetallePedidoVenta extends Model
{
    protected $table = 'detalle_pedidos_venta';

    protected $fillable = [
        'pedido_venta_id', 'producto_id', 'presentacion_id', 'descripcion', 'cantidad', 'precio_estimado',
    ];

    protected $casts = [
        'cantidad' => 'decimal:3',
        'precio_estimado' => 'decimal:2',
    ];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(PedidoVenta::class, 'pedido_venta_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function presentacion(): BelongsTo
    {
        return $this->belongsTo(ProductoPresentacion::class, 'presentacion_id');
    }
}
