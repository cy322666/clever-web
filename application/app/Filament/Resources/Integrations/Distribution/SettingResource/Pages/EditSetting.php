<?php

namespace App\Filament\Resources\Integrations\Distribution\SettingResource\Pages;

use App\Filament\Resources\Integrations\Distribution\ScheduleResource;
use App\Filament\Resources\Integrations\Distribution\SettingResource;
use App\Filament\Resources\Integrations\DistributionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditSetting extends EditRecord
{
    protected static string $resource = SettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('logs')
                ->label('Настройки')
                ->url(DistributionResource::getUrl('edit', ['record' => Auth::user()->distribution_settings->id])),

            Actions\Action::make('schedule')
                ->label('График')
                ->url(ScheduleResource::getUrl())
        ];
    }
}
