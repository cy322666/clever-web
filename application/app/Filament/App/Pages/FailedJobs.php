<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Widgets\FailedJobsTable;
use App\Filament\App\Widgets\QueueOpsOverview;
use Filament\Pages\Page;

class FailedJobs extends Page
{
    protected static string $routePath = 'failed-jobs';

    protected static ?string $title = 'Ошибки очереди';

    protected static bool $shouldRegisterNavigation = false;

    protected ?string $subheading = 'Оперативная работа с failed_jobs, автоматический мониторинг и синхронизация с queue_monitors';

    public static function canAccess(): bool
    {
        return (bool)config('features.queues.failed_jobs_page', true)
            && auth()->check()
            && (bool)auth()->user()?->is_root;
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            QueueOpsOverview::class,
            FailedJobsTable::class,
        ];
    }
}
