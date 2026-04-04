<?php

namespace App\Filament\Resources\Integrations\AmoDataResource\Pages;

use App\Filament\Resources\Integrations\AmoDataResource;
use App\Helpers\Actions\UpdateButton;
use App\Helpers\Traits\SyncAmoCRMPage;
use App\Jobs\AmoData\RunSync;
use App\Models\amoCRM\Event;
use App\Models\amoCRM\Lead;
use App\Models\amoCRM\Task;
use App\Models\Integrations\AmoData\SyncRun;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

            Action::make('clearData')
                ->label('Стереть выгрузку')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Стереть выгруженные данные?')
                ->modalDescription(
                    'Будут удалены только локальные deals, tasks, events и sync history текущего клиента. Справочники amoCRM и настройки модуля останутся.'
                )
                ->action(function () {
                    $userId = $this->record->user_id;

                    $deleted = DB::transaction(function () use ($userId) {
                        $events = Event::query()->where('user_id', $userId)->delete();
                        $tasks = Task::query()->where('user_id', $userId)->delete();
                        $leads = Lead::query()->where('user_id', $userId)->delete();
                        $runs = SyncRun::query()->where('user_id', $userId)->delete();

                        $this->record->update([
                            'sync_status' => null,
                            'initial_synced_at' => null,
                            'last_attempt_at' => null,
                            'last_successful_sync_at' => null,
                            'leads_synced_at' => null,
                            'tasks_synced_at' => null,
                            'last_leads_count' => 0,
                            'last_tasks_count' => 0,
                            'last_events_count' => 0,
                            'last_error' => null,
                        ]);

                        return [
                            'events' => $events,
                            'tasks' => $tasks,
                            'leads' => $leads,
                            'runs' => $runs,
                        ];
                    });

                    Notification::make()
                        ->title('Выгруженные данные удалены')
                        ->body(
                            "Deals: {$deleted['leads']}, tasks: {$deleted['tasks']}, events: {$deleted['events']}, sync runs: {$deleted['runs']}"
                        )
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
