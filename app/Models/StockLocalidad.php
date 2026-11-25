<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Existencia de un producto en una localidad. Solo la escribe InventarioService. */
class StockLocalidad extends Model
{
    protected $table = 'stock_localidades';

    protected $fillable = ['empresa_id', 'localidad_id', 'producto_id', 'cantidad'];

    protected $casts = [
        'cantidad' => 'decimal:3',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function localidad(): BelongsTo
    {
        return $this->belongsTo(Localidad::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
