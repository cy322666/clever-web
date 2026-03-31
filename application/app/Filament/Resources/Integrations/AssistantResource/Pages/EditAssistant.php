<?php

namespace App\Filament\Resources\Integrations\AssistantResource\Pages;

use App\Filament\Resources\Integrations\AssistantResource;
use App\Helpers\Actions\UpdateButton;
use App\Helpers\Traits\SyncAmoCRMPage;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class EditAssistant extends EditRecord
{
    use SyncAmoCRMPage;

    protected static string $resource = AssistantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UpdateButton::activeUpdate($this->record),

            UpdateButton::amoCRMSyncButton(
                Auth::user()->account,
                fn() => $this->amocrmUpdate(),
            ),

            Action::make('regenerateToken')
                ->label('Обновить token')
                ->icon('heroicon-o-key')
                ->action(function () {
                    $this->record->service_token = Str::random(60);
                    $this->record->save();

                    Notification::make()
                        ->title('Service token обновлен')
                        ->success()
                        ->send();
                }),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['api_base_url'] = url('/api/assistant/' . Auth::user()->uuid);

        return $data;
    }
}
