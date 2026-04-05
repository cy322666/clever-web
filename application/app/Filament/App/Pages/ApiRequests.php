<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Widgets\ApiRequestsTable;
use Filament\Pages\Page;

class ApiRequests extends Page
{
    protected static string $routePath = 'api-requests';

    protected static ?string $title = 'API запросы';

    protected static bool $shouldRegisterNavigation = false;

    protected ?string $subheading = 'Журнал входящих API запросов по умолчанию за последние 24 часа';

    public static function canAccess(): bool
    {
        return auth()->check() && (bool)auth()->user()?->is_root;
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ApiRequestsTable::class,
        ];
    }
}
