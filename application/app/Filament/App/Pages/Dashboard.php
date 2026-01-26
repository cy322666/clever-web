<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Widgets\Market;
use Filament\Support\Enums\Width;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    use BaseDashboard\Concerns\HasFiltersAction;

    protected static string $routePath = 'dashboard';

    protected static ?string $title = 'Магазин';

    protected Width | string | null $maxContentWidth = Width::FiveExtraLarge;

    public function getWidgets(): array
    {
        return [
            Market::class
        ];
    }
}
