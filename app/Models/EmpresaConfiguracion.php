<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AmbienteEcf;
use App\Enums\DocumentoImpreso;
use App\Enums\FormaReembolso;
use App\Enums\MetodoCosto;
use App\Enums\ModoImpresionPos;
use App\Enums\RedondeoPrecio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Configuración fiscal 1:1 por empresa (antes EmpresaSettings/FacturacionSettings, globales vía
 * spatie-settings). dgii_api_key y certificado_password se cifran en reposo (cast 'encrypted'):
 * nunca en texto plano en BD, logs ni respuestas — accede a ellas solo por el atributo del
 * modelo, nunca con consultas crudas a la columna.
 */
class EmpresaConfiguracion extends Model
{
    use LogsActivity;

    protected $table = 'empresa_configuracion';

    protected $fillable = [
        'empresa_id',
        'aplica_itbis',
        'precio_incluye_itbis',
        'tasa_itbis_defecto',
        'tipo_comprobante_defecto',
        'permite_ventas_sin_comprobante',
        'modo_impresion_pos',
        'moneda',
        'dgii_api_key',
        'dgii_ambiente',
        'dgii_base_url',
        'certificado_path',
        'certificado_password',
        'certificado_vence',
        'metodo_costo',
        'redondeo_precio',
        'permite_stock_negativo',
        'acepta_devoluciones',
        'devolucion_plazo_dias',
        'devolucion_reembolsos',
        'devolucion_monto_supervisor',
        'color_primario',
        'color_secundario',
        'color_accent_pos',
        'compras_a_recepcion',
        'requisicion_sucursal_requiere_aprobacion',
        'dias_alerta_recepcion',
        'antirrobo_umbral_anulaciones',
        'antirrobo_umbral_descuentos',
        'antirrobo_umbral_devoluciones',
        'antirrobo_umbral_ajustes',
        'conteo_umbral_aprobacion_admin',
        'textos_documentos',
    ];

    protected $casts = [
        'aplica_itbis' => 'boolean',
        'precio_incluye_itbis' => 'boolean',
        'permite_ventas_sin_comprobante' => 'boolean',
        'modo_impresion_pos' => ModoImpresionPos::class,
        'dgii_ambiente' => AmbienteEcf::class,
        'dgii_api_key' => 'encrypted',
        'certificado_password' => 'encrypted',
        'certificado_vence' => 'date',
        'metodo_costo' => MetodoCosto::class,
        'redondeo_precio' => RedondeoPrecio::class,
        'permite_stock_negativo' => 'boolean',
        'acepta_devoluciones' => 'boolean',
        'devolucion_plazo_dias' => 'integer',
        'devolucion_reembolsos' => 'array',
        'devolucion_monto_supervisor' => 'decimal:2',
        'textos_documentos' => 'array',
        'compras_a_recepcion' => 'boolean',
        'requisicion_sucursal_requiere_aprobacion' => 'boolean',
        'dias_alerta_recepcion' => 'integer',
        'antirrobo_umbral_anulaciones' => 'integer',
        'antirrobo_umbral_descuentos' => 'decimal:2',
        'antirrobo_umbral_devoluciones' => 'integer',
        'antirrobo_umbral_ajustes' => 'decimal:2',
        'conteo_umbral_aprobacion_admin' => 'decimal:2',
    ];

    // Reflejan los defaults de la columna en la migración: sin esto, un ::create()/firstOrCreate()
    // que omite estos campos (el caso normal de Empresa::config()) deja el modelo en memoria con
    // null/'' hasta refrescarlo desde la BD (mismo patrón ya documentado en Impresora/User).
    protected $attributes = [
        'aplica_itbis' => true,
        'precio_incluye_itbis' => false,
        'tasa_itbis_defecto' => '18',
        'tipo_comprobante_defecto' => '32',
        'permite_ventas_sin_comprobante' => false,
        'modo_impresion_pos' => 'directa',
        'moneda' => 'DOP',
        'dgii_ambiente' => 'TesteCF',
        'dgii_base_url' => '',
        'metodo_costo' => 'ultima_compra',
        'redondeo_precio' => 'ninguno',
        'permite_stock_negativo' => false,
        'acepta_devoluciones' => true,
        'color_primario' => '#0C8A63',
        'color_secundario' => '#0B1B2B',
        'color_accent_pos' => '#e85d9a',
        'compras_a_recepcion' => false,
        'requisicion_sucursal_requiere_aprobacion' => false,
        'dias_alerta_recepcion' => 3,
        'antirrobo_umbral_anulaciones' => 3,
        'antirrobo_umbral_descuentos' => '1000',
        'antirrobo_umbral_devoluciones' => 3,
        'antirrobo_umbral_ajustes' => '2000',
        'conteo_umbral_aprobacion_admin' => '5000',
    ];

    /**
     * Formas de reembolso que la empresa acepta en una devolución. null en la columna = todas.
     *
     * @return array<int, FormaReembolso>
     */
    /**
     * Texto configurado para un campo de un documento impreso. Si la empresa nunca lo configuró,
     * devuelve el texto por defecto del documento; si lo dejó en blanco a propósito, null (no se
     * imprime nada).
     */
    public function textoDocumento(DocumentoImpreso $documento, string $campo = 'pie'): ?string
    {
        $textos = $this->textos_documentos ?? [];

        if (! array_key_exists($documento->value, $textos) || ! array_key_exists($campo, $textos[$documento->value] ?? [])) {
            return $documento->textoPorDefecto($campo);
        }

        $valor = trim((string) $textos[$documento->value][$campo]);

        return $valor === '' ? null : $valor;
    }

    public function reembolsosPermitidos(): array
    {
        if (blank($this->devolucion_reembolsos)) {
            return FormaReembolso::cases();
        }

        return collect($this->devolucion_reembolsos)
            ->map(fn (string $valor) => FormaReembolso::tryFrom($valor))
            ->filter()
            ->values()
            ->all();
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function tieneCertificado(): bool
    {
        return filled($this->certificado_path);
    }

    /** true si el certificado vence en 30 días o menos (o ya venció). */
    public function certificadoPorVencer(): bool
    {
        return $this->certificado_vence !== null
            && now()->addDays(30)->greaterThanOrEqualTo($this->certificado_vence);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            // Nunca en el log de auditoría: dgii_api_key, certificado_password, certificado_path.
            ->logOnly([
                'aplica_itbis',
                'precio_incluye_itbis',
                'tasa_itbis_defecto',
                'tipo_comprobante_defecto',
                'moneda',
                'dgii_ambiente',
                'certificado_vence',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('Configuración fiscal');
    }
}
