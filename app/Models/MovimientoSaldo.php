<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoMovimientoSaldo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimientoSaldo extends Model
{
    protected $table = 'movimientos_saldo';

    protected $fillable = [
        'empresa_id',
        'cliente_id',
        'tipo',
        'monto',
        'saldo_anterior',
        'saldo_posterior',
        'concepto',
        'venta_id',
        'user_id',
    ];

    protected $casts = [
        'tipo' => TipoMovimientoSaldo::class,
        'monto' => 'decimal:2',
        'saldo_anterior' => 'decimal:2',
        'saldo_posterior' => 'decimal:2',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
