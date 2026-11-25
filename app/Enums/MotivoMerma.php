<?php

declare(strict_types=1);

namespace App\Enums;

/** Por qué se perdió la mercancía registrada como merma en el Kardex: dañado o podrido, vencido u otro. */
enum MotivoMerma: string
{
    case DANADO = 'danado';
    case VENCIDO = 'vencido';
    case OTRO = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::DANADO => 'Dañado o podrido',
            self::VENCIDO => 'Vencido',
            self::OTRO => 'Otro',
        };
    }

    /** @return array<string, string> */
    public static function opciones(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $motivo) => [$motivo->value => $motivo->etiqueta()])->all();
    }
}
