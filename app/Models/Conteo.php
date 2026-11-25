<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoConteo;
use App\Enums\TipoConteo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conteo extends Model
{
    protected $table = 'conteos';

    protected $fillable = [
        'empresa_id', 'numero', 'localidad_id', 'sucursal_id', 'tipo', 'estado', 'asignado_a', 'creado_por',
        'aprobado_por', 'aprobado_en', 'cerrado_en', 'valor_diferencia', 'notas', 'motivo_cancelacion',
    ];

    protected $casts = [
        'numero' => 'integer',
        'tipo' => TipoConteo::class,
        'estado' => EstadoConteo::class,
        'aprobado_en' => 'datetime',
        'cerrado_en' => 'datetime',
        'valor_diferencia' => 'decimal:2',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function localidad(): BelongsTo
    {
        return $this->belongsTo(Localidad::class);
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function asignado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asignado_a');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleConteo::class);
    }

    public function etiquetaNumero(): string
    {
        return 'CF-'.str_pad((string) $this->numero, 5, '0', STR_PAD_LEFT);
    }
}
