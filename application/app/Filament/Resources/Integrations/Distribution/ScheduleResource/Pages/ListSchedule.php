<?php

namespace App\Filament\Resources\Integrations\Distribution\ScheduleResource\Pages;

use App\Filament\Resources\Integrations\Distribution\ScheduleResource;
use App\Filament\Resources\Integrations\Distribution\ScheduleResource\Widgets\DistributionScheduleCalendar;
use App\Filament\Resources\Integrations\DistributionResource;
use Filament\Actions;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Auth;

class ListSchedule extends Page
{
    protected static string $resource = ScheduleResource::class;

    protected ?string $heading = 'График распределения';

    public function getHeaderWidgetsColumns(): int | array
    {
        return 1;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            DistributionScheduleCalendar::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('settings')
                ->label('Настройки')
                ->icon('heroicon-o-cog-6-tooth')
                ->url(DistributionResource::getUrl('edit', ['record' => Auth::user()->distribution_settings->id])),
        ];
    }
}
