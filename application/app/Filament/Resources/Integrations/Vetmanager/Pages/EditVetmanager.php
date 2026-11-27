<?php

namespace App\Filament\Resources\Integrations\Vetmanager\Pages;

use App\Filament\Resources\Integrations\Vetmanager\VetmanagerResource;
use App\Helpers\Actions\UpdateButton;
use App\Helpers\Traits\SyncAmoCRMPage;
use App\Models\Integrations\Vetmanager\Setting;
use App\Services\Vetmanager\VetmanagerApiClient;
use App\Services\Vetmanager\VetmanagerWebhookManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Log;
use Throwable;

class EditVetmanager extends EditRecord
{
    use SyncAmoCRMPage;

    protected static string $resource = VetmanagerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UpdateButton::activeUpdate($this->record),

            UpdateButton::amoCRMSyncButton(
                $this->record->amoAccount(true, 'vetmanager'),
                fn () => $this->amocrmUpdate(),
            ),

            $this->vetmanagerConnectionAction(),

            Action::make('history')
                ->label('История')
                ->icon('heroicon-o-list-bullet')
                ->url(VetmanagerResource::getUrl('visits')),
        ];
    }

    protected function vetmanagerConnectionAction(): Action
    {
        return Action::make('install_webhook')
            ->label(fn (): string => filled($this->record->webhook_id)
                ? 'Проверить Ветменеджер'
                : 'Подключить Ветменеджер')
            ->icon(fn (): string => filled($this->record->webhook_id)
                ? 'heroicon-o-signal'
                : 'heroicon-o-link')
            ->action(function (): void {
                $this->record->refresh();

                if (filled($this->record->webhook_id)) {
                    $this->checkConnection();

                    return;
                }

                $this->installWebhook();
            });
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['api_key'] = null;
        $data['webhook_url'] = $this->record->webhookUrl();
        $data['fields_contact'] = Setting::fieldMappingRows($data, 'contacts');
        $data['fields_lead'] = Setting::fieldMappingRows($data, 'leads');

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = array_merge(
            $data,
            Setting::fieldMappingAttributes($data['fields_contact'] ?? [], 'contacts'),
            Setting::fieldMappingAttributes($data['fields_lead'] ?? [], 'leads'),
        );

        unset(
            $data['webhook_url'],
            $data['vetmanager_connection'],
            $data['visits_count'],
            $data['entities'],
            $data['pricing'],
            $data['fields_contact'],
            $data['fields_lead'],
        );

        if (blank($data['api_key'] ?? null)) {
            unset($data['api_key']);
        }

        $data['base_url'] = VetmanagerApiClient::normalizeBaseUrl((string) ($data['base_url'] ?? ''));

        return $data;
    }

    private function checkConnection(): void
    {
        try {
            app(VetmanagerWebhookManager::class)->verify($this->record);

            Notification::make()
                ->title('Вебхук Ветменеджера активен')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            $this->failureNotification('Не удалось проверить Ветменеджер', $exception);
        }
    }

    public function installWebhook(): void
    {
        try {
            $this->save(false, false);
            $this->record->refresh();
            app(VetmanagerWebhookManager::class)->synchronize($this->record);
            $this->refreshFormData(['webhook_synced_at']);

            Notification::make()
                ->title('Вебхук Ветменеджера подключен')
                ->body('Будут обрабатываться создание, изменение, статусы и удаление приемов.')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            $this->failureNotification('Не удалось подключить вебхук Ветменеджера', $exception);
        }
    }

    private function failureNotification(string $title, Throwable $exception): void
    {
        Log::error($title, [
            'setting_id' => $this->record->id,
            'error' => $exception->getMessage(),
        ]);

        Notification::make()
            ->title($title)
            ->body($exception->getMessage())
            ->danger()
            ->send();
    }
}
