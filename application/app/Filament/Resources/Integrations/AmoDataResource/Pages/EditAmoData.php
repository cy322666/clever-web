<?php

namespace App\Filament\Resources\Integrations\AmoDataResource\Pages;

use App\Filament\Resources\Integrations\AmoDataResource;
use App\Helpers\Actions\UpdateButton;
use App\Helpers\Traits\SyncAmoCRMPage;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Artisan;
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
                    Artisan::call('app:amo-data-sync', [
                        'user_id' => Auth::id(),
                        '--initial' => true,
                    ]);

                    Notification::make()
                        ->title('Initial sync завершен')
                        ->success()
                        ->send();
                }),

            Action::make('periodicSync')
                ->label('Periodic sync')
                ->icon('heroicon-o-arrow-path')
                ->action(function () {
                    Artisan::call('app:amo-data-sync', [
                        'user_id' => Auth::id(),
                    ]);

                    Notification::make()
                        ->title('Periodic sync завершен')
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
