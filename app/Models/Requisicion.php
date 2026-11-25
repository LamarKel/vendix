<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoRequisicion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Requisicion extends Model
{
    public const TIPO_REQUISICION = 'requisicion';

    public const TIPO_MOVIMIENTO = 'movimiento';

    protected $table = 'requisiciones';

    protected $fillable = [
        'empresa_id', 'numero', 'tipo', 'estado', 'localidad_origen_id', 'localidad_destino_id',
        'sucursal_origen_id', 'sucursal_destino_id', 'entre_sucursales', 'notas', 'solicitado_por',
        'aprobado_por', 'aprobado_en', 'despachado_por', 'despachado_en', 'recibido_por', 'recibido_en',
        'cancelado_por', 'cancelado_en', 'motivo_cancelacion',
    ];

    protected $casts = [
        'numero' => 'integer',
        'estado' => EstadoRequisicion::class,
        'entre_sucursales' => 'boolean',
        'aprobado_en' => 'datetime',
        'despachado_en' => 'datetime',
        'recibido_en' => 'datetime',
        'cancelado_en' => 'datetime',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function localidadOrigen(): BelongsTo
    {
        return $this->belongsTo(Localidad::class, 'localidad_origen_id');
    }

    public function localidadDestino(): BelongsTo
    {
        return $this->belongsTo(Localidad::class, 'localidad_destino_id');
    }

    public function sucursalOrigen(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_origen_id');
    }

    public function sucursalDestino(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_destino_id');
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }

    public function despachador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'despachado_por');
    }

    public function receptor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recibido_por');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleRequisicion::class);
    }

    public function etiquetaNumero(): string
    {
        return ($this->tipo === self::TIPO_MOVIMIENTO ? 'MI-' : 'REQ-').str_pad((string) $this->numero, 5, '0', STR_PAD_LEFT);
    }

    public function esMovimientoInterno(): bool
    {
        return $this->tipo === self::TIPO_MOVIMIENTO;
    }
}
