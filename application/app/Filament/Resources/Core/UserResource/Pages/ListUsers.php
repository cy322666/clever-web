<?php

namespace App\Filament\Resources\Core\UserResource\Pages;

use App\Filament\App\Pages\AppStats;
use App\Filament\App\Pages\Backup;
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

        if (Route::has('telescope')) {
            $actions[] = Action::make('telescope')
                ->label('Телескоп')
                ->url(fn(): string => route('telescope'))
                ->openUrlInNewTab()
                ->color(Color::Blue);
        }

        if (Route::has('filament.app.resources.queue-monitors.index')) {
            $actions[] = Action::make('queues')
                ->label('Очереди')
                ->url(fn(): string => QueueMonitorResource::getUrl(panel: 'app'))
                ->openUrlInNewTab()
                ->color(Color::Green);
        }

        return array_merge($actions, [

//            Action::make('horizon')
//                ->label('Горизонт')
//                ->url(route('horizon.index'))
//                ->openUrlInNewTab()
//                ->color(Color::Fuchsia),

//            Actions\Action::make('totem')
//                ->label('Тотем')
//                ->url(route('totem.dashboard'))
//                ->openUrlInNewTab()
//                ->color(Color::Green),
//
            Action::make('auths')
                ->label('Авторизации')
                ->url(env('APP_URL') . '/panel/authentication-logs')
                ->openUrlInNewTab()
                ->color(Color::Green),

            Action::make('apps')
                ->label('Приложения')
                ->url(AppStats::getUrl())
                ->openUrlInNewTab()
                ->color(Color::Green),

            Action::make('backups')
                ->label('Бэкапы')
                ->url(Backup::getUrl())
                ->openUrlInNewTab()
                ->color(Color::Green),

        ]);
    }
}
