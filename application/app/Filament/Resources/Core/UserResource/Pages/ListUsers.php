<?php

namespace App\Filament\Resources\Core\UserResource\Pages;

use App\Filament\App\Pages\AppStats;
use App\Filament\App\Pages\Backup;
use App\Filament\Resources\Core\App\AppResource;
use App\Filament\Resources\Core\UserResource;
use App\Models\amoCRM\Staff;
use App\Services\amoCRM\Client;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\Auth;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getActions(): array
    {
        return [

            Action::make('telescope')
                ->label('Телескоп')
                ->url(route('telescope'))
                ->openUrlInNewTab()
                ->color(Color::Blue),

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

        ];
    }
}
