<?php

namespace App\Filament\Resources\Integrations\AssistantResource\Pages;

use App\Filament\Resources\Integrations\AmoDataResource;
use App\Filament\Resources\Integrations\AssistantResource;
use App\Helpers\Actions\UpdateButton;
use App\Helpers\Traits\SyncAmoCRMPage;
use App\Models\App;
use App\Models\Integrations\AmoData\Setting as AmoDataSetting;
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
                $this->record->amoAccount(true),
                fn() => $this->amocrmUpdate(),
            ),

            Action::make('amoDataSettings')
                ->label('Настройки выгрузки')
                ->icon('heroicon-o-circle-stack')
                ->action(function () {
                    $user = Auth::user();

                    $setting = AmoDataSetting::query()->firstOrCreate([
                        'user_id' => $user->id,
                    ]);

                    App::query()->updateOrCreate([
                        'user_id' => $user->id,
                        'name' => 'amo-data',
                    ], [
                        'setting_id' => $setting->id,
                        'resource_name' => AmoDataResource::class,
                    ]);

                    $this->redirect(AmoDataResource::getUrl('edit', ['record' => $setting]));
                }),

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
