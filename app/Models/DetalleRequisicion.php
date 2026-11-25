<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetalleRequisicion extends Model
{
    protected $table = 'detalle_requisiciones';

    protected $fillable = [
        'requisicion_id', 'producto_id', 'cantidad_solicitada', 'cantidad_despachada', 'cantidad_recibida',
        'nota_despacho', 'nota_recepcion',
    ];

    protected $casts = [
        'cantidad_solicitada' => 'decimal:3',
        'cantidad_despachada' => 'decimal:3',
        'cantidad_recibida' => 'decimal:3',
    ];

    public function requisicion(): BelongsTo
    {
        return $this->belongsTo(Requisicion::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /** Despachado que no llegó (null mientras no se reciba). */
    public function faltante(): ?string
    {
        if ($this->cantidad_recibida === null || $this->cantidad_despachada === null) {
            return null;
        }

        return bcsub((string) $this->cantidad_despachada, (string) $this->cantidad_recibida, 3);
    }
}
