<?php

namespace App\Models;

use App\Enums\TipoDocumentoCliente;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Cliente extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'empresa_id', 'tipo_documento', 'documento', 'nombre',
        'telefono', 'email', 'direccion', 'activo', 'lista_precio_id',
        'saldo_favor',
    ];

    protected $casts = [
        'tipo_documento' => TipoDocumentoCliente::class,
        'activo' => 'boolean',
        'saldo_favor' => 'decimal:2',
    ];

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function listaPrecio(): BelongsTo
    {
        return $this->belongsTo(ListaPrecio::class);
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class);
    }

    public function movimientosSaldo(): HasMany
    {
        return $this->hasMany(MovimientoSaldo::class);
    }

    public function tieneSaldoFavor(): bool
    {
        return bccomp((string) $this->saldo_favor, '0.00', 2) > 0;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['tipo_documento', 'documento', 'nombre', 'telefono', 'email', 'direccion', 'activo'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('Clientes');
    }
}
