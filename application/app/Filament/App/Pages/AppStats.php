<?php

namespace App\Filament\App\Pages;

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
            \App\Filament\App\Widgets\AppStats::class,
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
