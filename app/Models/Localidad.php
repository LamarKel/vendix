<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoLocalidad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Localidad extends Model
{
    protected $table = 'localidades';

    protected $fillable = ['empresa_id', 'sucursal_id', 'nombre', 'tipo', 'activa'];

    protected $casts = [
        'tipo' => TipoLocalidad::class,
        'activa' => 'boolean',
    ];

    protected $attributes = [
        'activa' => true,
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(StockLocalidad::class);
    }

    /** "Principal · Tienda" */
    public function nombreCompleto(): string
    {
        return ($this->sucursal?->nombre ?? '').' · '.$this->nombre;
    }
}
