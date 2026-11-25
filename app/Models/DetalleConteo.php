<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetalleConteo extends Model
{
    protected $table = 'detalle_conteos';

    protected $fillable = [
        'conteo_id', 'producto_id', 'cantidad_contada', 'existencia_al_contar', 'costo_unitario',
        'contado_por', 'contado_en', 'veces_contado',
    ];

    protected $casts = [
        'cantidad_contada' => 'decimal:3',
        'existencia_al_contar' => 'decimal:3',
        'costo_unitario' => 'decimal:2',
        'contado_en' => 'datetime',
        'veces_contado' => 'integer',
    ];

    public function conteo(): BelongsTo
    {
        return $this->belongsTo(Conteo::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function contador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contado_por');
    }

    /** Contado − sistema (null si no se ha contado). Negativo = falta. */
    public function diferencia(): ?string
    {
        if ($this->cantidad_contada === null || $this->existencia_al_contar === null) {
            return null;
        }

        return bcsub((string) $this->cantidad_contada, (string) $this->existencia_al_contar, 3);
    }

    public function valorDiferencia(): ?string
    {
        $d = $this->diferencia();

        return $d === null ? null : bcmul($d, (string) ($this->costo_unitario ?? '0'), 2);
    }
}
