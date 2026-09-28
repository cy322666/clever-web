<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Widgets\Market;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Enums\Width;

class Dashboard extends BaseDashboard
{
    protected static string $routePath = 'dashboard';

    protected static ?string $title = 'Интеграции';

    protected ?string $heading = '';

    protected Width|string|null $maxContentWidth = Width::FiveExtraLarge;

    public function getWidgets(): array
    {
        return [
            Market::class,
        ];
    }
}
