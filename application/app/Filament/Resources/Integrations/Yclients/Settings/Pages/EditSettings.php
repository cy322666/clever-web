<?php

namespace App\Filament\Resources\Integrations\Yclients\Settings\Pages;

use App\Filament\Resources\Integrations\Yclients\Settings\SettingsResource;
use App\Helpers\Actions\UpdateButton;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditSettings extends EditRecord
{
    protected static string $resource = SettingsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UpdateButton::amoCRMSyncButton(Auth::user()->account),

            //TODO синхр с ус
        ];
    }
}
