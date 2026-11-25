<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reportes;

use App\Services\AlertaAntirroboService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Control antirrobo: ranking por persona de anulaciones, descuentos, devoluciones y faltantes. */
class ReporteControl extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?string $navigationLabel = 'Control antirrobo';

    protected static string|\UnitEnum|null $navigationGroup = 'Financieros';

    protected static ?int $navigationSort = 58;

    protected static ?string $title = 'Control antirrobo';

    protected static ?string $slug = 'reportes/control';

    protected string $view = 'filament.pages.reporte-control';

    public string $desde = '';

    public string $hasta = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('reportes.control') ?? false;
    }

    public function mount(): void
    {
        $this->desde = now()->subDays(6)->toDateString();
        $this->hasta = now()->toDateString();
    }

    private function rango(): array
    {
        $desde = rescue(fn () => Carbon::parse($this->desde), now()->subDays(6), false);
        $hasta = rescue(fn () => Carbon::parse($this->hasta), now(), false);

        // Tope de 1 año por consulta para no recorrer toda la historia de golpe.
        if ($desde->diffInDays($hasta) > 366) {
            $desde = $hasta->copy()->subYear();
        }

        return [$desde, $hasta];
    }

    public function ranking(): Collection
    {
        [$d, $h] = $this->rango();

        return app(AlertaAntirroboService::class)->ranking(Filament::getTenant(), $d, $h);
    }

    /** @return array<int, array{color: string, titulo: string, detalle: string}> */
    public function senales(): array
    {
        [$d, $h] = $this->rango();

        return app(AlertaAntirroboService::class)->senales(Filament::getTenant(), $d, $h);
    }
}
