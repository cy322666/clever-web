<?php

namespace App\Filament\App\Pages;

use Filament\Pages\Page;
use Filament\Panel;

class AppStats extends Page
{
    protected static ?string $title = 'Статистика приложений';

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\App\Widgets\AppStats::class
        ];
    }
}
