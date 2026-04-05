<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Widgets\AppStatsInstallTrendChart;
use App\Filament\App\Widgets\AppStatsOverview;
use App\Filament\App\Widgets\AppStatsStatusChart;
use App\Filament\App\Widgets\QueueOpsOverview;
use Filament\Pages\Page;

class AppStats extends Page
{
    protected static ?string $title = 'Статистика приложений';

    protected ?string $subheading = 'Воронка подключений, состояние интеграций и операционная нагрузка';

    public static function canAccess(): bool
    {
        return auth()->check() && (bool)auth()->user()?->is_root;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            AppStatsOverview::class,
            AppStatsInstallTrendChart::class,
            AppStatsStatusChart::class,
            \App\Filament\App\Widgets\AppStats::class,
            QueueOpsOverview::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return [
            'md' => 2,
            'xl' => 2,
        ];
    }
}
