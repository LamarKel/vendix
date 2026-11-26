<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Empresa;
use Illuminate\Support\Facades\Storage;

/**
 * Variables comunes de los PDF (factura, cotización): colores de la empresa (o los de Vendix),
 * tintes claros para DomPDF (no entiende transparencias en hex), logo embebido y nombre.
 * Uso en la vista: @php(extract(\App\Support\EstiloPdf::para($empresa))).
 */
class EstiloPdf
{
    /** @return array{cp: string, cs: string, cpSuave: string, cpBorde: string, logoPdf: ?string, nombreEmpresaPdf: string} */
    public static function para(Empresa $empresa): array
    {
        $cfg = $empresa->config();
        $cp = self::hex($cfg->color_primario, '#0C8A63');
        $cs = self::hex($cfg->color_secundario, '#0B1B2B');

        $logo = null;
        if ($empresa->logo) {
            $ruta = Storage::disk('public')->path($empresa->logo);
            if (is_file($ruta)) {
                $logo = 'data:'.(mime_content_type($ruta) ?: 'image/png').';base64,'.base64_encode((string) file_get_contents($ruta));
            }
        }

        return [
            'cp' => $cp,
            'cs' => $cs,
            'cpSuave' => self::tinte($cp, 0.08),
            'cpBorde' => self::tinte($cp, 0.25),
            'logoPdf' => $logo,
            'nombreEmpresaPdf' => (string) ($empresa->nombre_comercial ?: $empresa->razon_social),
        ];
    }

    private static function hex(?string $valor, string $porDefecto): string
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $valor) ? (string) $valor : $porDefecto;
    }

    private static function tinte(string $hex, float $p): string
    {
        $c = fn (int $i) => (int) round(hexdec(substr($hex, $i, 2)) * $p + 255 * (1 - $p));

        return sprintf('#%02X%02X%02X', $c(1), $c(3), $c(5));
    }
}
