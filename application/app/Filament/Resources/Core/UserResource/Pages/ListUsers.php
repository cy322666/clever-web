<?php

namespace App\Filament\Resources\Core\UserResource\Pages;

use App\Filament\App\Pages\AppStats;
use App\Filament\App\Pages\Backup;
use App\Filament\App\Pages\FailedJobs;
use App\Filament\Resources\Core\UserResource;
use Croustibat\FilamentJobsMonitor\Resources\QueueMonitorResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\Route;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getActions(): array
    {
        $actions = [];

        if (
            config('features.panel_actions.queues', true)
            && Route::has('filament.app.resources.queue-monitors.index')
        ) {
            $actions[] = Action::make('queues')
                ->label('Очереди')
                ->url(fn(): string => QueueMonitorResource::getUrl(panel: 'app'))
                ->openUrlInNewTab()
                ->color(Color::Green);
        }

        if (
            config('features.panel_actions.failed_jobs', true)
            && config('features.queues.failed_jobs_page', true)
            && Route::has('filament.app.pages.failed-jobs')
        ) {
            $actions[] = Action::make('failed_jobs')
                ->label('Ошибки очереди')
                ->url(fn(): string => FailedJobs::getUrl())
                ->openUrlInNewTab()
                ->color('danger');
        }

        if (config('features.panel_actions.auth_logs', true)) {
            $actions[] = Action::make('auths')
                ->label('Авторизации')
                ->url(env('APP_URL') . '/panel/authentication-logs')
                ->openUrlInNewTab()
                ->color(Color::Green);
        }

        if (config('features.panel_actions.apps_stats', true)) {
            $actions[] = Action::make('apps')
                ->label('Приложения')
                ->url(AppStats::getUrl())
                ->openUrlInNewTab()
                ->color(Color::Green);
        }

        if (config('features.panel_actions.backups', true)) {
            $actions[] = Action::make('backups')
                ->label('Бэкапы')
                ->url(Backup::getUrl())
                ->openUrlInNewTab()
                ->color(Color::Green);
        }

        return $actions;
    }
}
