<?php

namespace App\Filament\Resources\Integrations\Yclients\Settings\Pages;

use App\Filament\Resources\Integrations\Yclients\Settings\SettingsResource;
use App\Helpers\Actions\UpdateButton;
use App\Helpers\Traits\SyncAmoCRMPage;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditSettings extends EditRecord
{
    use SyncAmoCRMPage;

    protected static string $resource = SettingsResource::class;

    protected function getHeaderActions(): array
    {
        return [
//            UpdateButton::activeUpdate($this->record),
//
//            UpdateButton::amoCRMSyncButton(
//                Auth::user()->account,
//                fn () => $this->amocrmUpdate(),
//            ),

            //TODO синхр с ус
        ];
    }
}
