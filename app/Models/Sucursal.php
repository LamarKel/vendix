<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoLocalidad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sucursal extends Model
{
    protected $table = 'sucursales';

    protected $fillable = ['empresa_id', 'nombre', 'codigo', 'direccion', 'telefono', 'es_principal', 'activa'];

    protected $casts = [
        'es_principal' => 'boolean',
        'activa' => 'boolean',
    ];

    protected $attributes = [
        'es_principal' => false,
        'activa' => true,
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function localidades(): HasMany
    {
        return $this->hasMany(Localidad::class);
    }

    public function localidadDeTipo(TipoLocalidad $tipo): ?Localidad
    {
        return $this->localidades()->where('tipo', $tipo->value)->where('activa', true)->first();
    }
}
