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

            Action::make('test_vetmanager')
                ->label('Проверить доступ')
                ->icon('heroicon-o-signal')
                ->action(fn () => $this->testConnection()),

            Action::make('install_webhook')
                ->label('Подключить Vetmanager')
                ->icon('heroicon-o-link')
                ->action(fn () => $this->installWebhook()),

            Action::make('history')
                ->label('История')
                ->icon('heroicon-o-list-bullet')
                ->url(VetmanagerResource::getUrl('visits')),
        ];
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

    public function testConnection(): void
    {
        try {
            $this->save(false, false);
            $this->record->refresh();
            (new VetmanagerApiClient($this->record))->ping();

            Notification::make()
                ->title('Доступ к Vetmanager подтвержден')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            $this->failureNotification('Не удалось подключиться к Vetmanager', $exception);
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
                ->title('Webhook Vetmanager подключен')
                ->body('Будут обрабатываться прием и изменение его суммы.')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            $this->failureNotification('Не удалось подключить webhook Vetmanager', $exception);
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
