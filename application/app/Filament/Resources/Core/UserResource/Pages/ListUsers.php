<?php

namespace App\Filament\Resources\Core\UserResource\Pages;

use App\Filament\Resources\Core\LogResource;
use App\Filament\Resources\Core\UserResource;
use App\Models\amoCRM\Staff;
use App\Services\amoCRM\Client;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\Auth;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getActions(): array
    {
        return [
            Actions\Action::make('logs')
                ->label('Логи')
                ->url(LogResource::getUrl())
                ->color(Color::Cyan),

            Actions\Action::make('telescope')
                ->label('Телескоп')
                ->url(route('telescope'))
                ->openUrlInNewTab()
                ->color(Color::Blue),

            Actions\Action::make('horizon')
                ->label('Горизонт')
                ->url(route('horizon.index'))
                ->openUrlInNewTab()
                ->color(Color::Fuchsia),

            Actions\Action::make('totem')
                ->label('Тотем')
                ->url(route('totem.dashboard'))
                ->openUrlInNewTab()
                ->color(Color::Green),
        ];
    }
}
