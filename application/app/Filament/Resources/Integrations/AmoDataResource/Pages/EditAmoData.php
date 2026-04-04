<?php

namespace App\Filament\Resources\Integrations\AmoDataResource\Pages;

use App\Filament\Resources\Integrations\AmoDataResource;
use App\Helpers\Actions\UpdateButton;
use App\Helpers\Traits\SyncAmoCRMPage;
use App\Jobs\AmoData\RunSync;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Auth;

class EditAmoData extends EditRecord
{
    use SyncAmoCRMPage;

    protected static string $resource = AmoDataResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UpdateButton::activeUpdate($this->record),

            UpdateButton::amoCRMSyncButton(
                Auth::user()->account,
                fn() => $this->amocrmUpdate(),
            ),

            Action::make('initialSync')
                ->label('Initial sync')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(function () {
                    if (Config::get('queue.default') === 'sync' && !App::environment('local')) {
                        Notification::make()
                            ->title('Очередь настроена в sync, initial sync через UI отключен')
                            ->danger()
                            ->send();

                        return;
                    }

                    RunSync::dispatch($this->record->id, 'initial');

                    Notification::make()
                        ->title('Initial sync поставлен в очередь')
                        ->success()
                        ->send();
                }),

            Action::make('periodicSync')
                ->label('Periodic sync')
                ->icon('heroicon-o-arrow-path')
                ->action(function () {
                    if (Config::get('queue.default') === 'sync' && !App::environment('local')) {
                        Notification::make()
                            ->title('Очередь настроена в sync, periodic sync через UI отключен')
                            ->danger()
                            ->send();

                        return;
                    }

                    RunSync::dispatch($this->record->id, 'periodic');

                    Notification::make()
                        ->title('Periodic sync поставлен в очередь')
                        ->success()
                        ->send();
                }),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['settings'] = $this->normalizeSettings($data['settings'] ?? null);

        return $data;
    }

    private function normalizeSettings(mixed $settings): array
    {
        if (is_string($settings) && $settings !== '') {
            $decoded = json_decode($settings, true);
            $settings = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($settings)) {
            $settings = [];
        }

        return array_merge([
            'sync_interval_minutes' => 30,
            'sync_deals' => true,
            'sync_tasks' => true,
            'store_payloads' => true,
        ], $settings);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['settings'] = $this->normalizeSettings($data['settings'] ?? null);

        return $data;
    }
}
