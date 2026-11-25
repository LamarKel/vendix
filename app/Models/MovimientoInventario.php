<?php

namespace App\Models;

use App\Enums\MotivoMerma;
use App\Enums\OrigenMovimiento;
use App\Enums\TipoMovimiento;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MovimientoInventario extends Model
{
    use HasFactory;

    protected $table = 'movimientos_inventario';

    protected $fillable = [
        'empresa_id', 'producto_id', 'localidad_id', 'tipo', 'origen', 'motivo', 'referencia_id',
        'revierte_movimiento_id', 'cantidad', 'costo_unitario', 'stock_anterior', 'stock_nuevo',
        'user_id', 'observacion', 'dejo_stock_negativo', 'stock_localidad_anterior', 'stock_localidad_nuevo',
    ];

    protected $casts = [
        'tipo' => TipoMovimiento::class,
        'origen' => OrigenMovimiento::class,
        'motivo' => MotivoMerma::class,
        'cantidad' => 'decimal:3',
        'costo_unitario' => 'decimal:2',
        'stock_anterior' => 'decimal:3',
        'stock_localidad_anterior' => 'decimal:3',
        'stock_localidad_nuevo' => 'decimal:3',
        'stock_nuevo' => 'decimal:3',
        'dejo_stock_negativo' => 'boolean',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** El movimiento que este revierte (p. ej. la merma original de una anulación). */
    public function revierte(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revierte_movimiento_id');
    }

    /** El movimiento que anuló a este, si existe. */
    public function reversion(): HasOne
    {
        return $this->hasOne(self::class, 'revierte_movimiento_id');
    }

    /**
     * Merma registrada a mano desde la pantalla de Mermas (las que salen de una devolución de
     * cliente tienen referencia a la nota de crédito y no llevan motivo).
     */
    public function esMermaManual(): bool
    {
        return $this->origen === OrigenMovimiento::MERMA
            && $this->tipo === TipoMovimiento::SALIDA
            && $this->motivo !== null
            && $this->referencia_id === null;
    }

    /** Valor de lo perdido: cantidad × costo guardado (o el costo actual si es anterior a ese campo). */
    public function valorPerdido(): string
    {
        $costo = $this->costo_unitario ?? $this->producto?->costo ?? '0';

        return bcmul((string) $this->cantidad, (string) $costo, 2);
    }

    public function localidad(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Localidad::class);
    }
}
