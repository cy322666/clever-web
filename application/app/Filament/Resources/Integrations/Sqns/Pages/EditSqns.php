<?php

namespace App\Filament\Resources\Integrations\Sqns\Pages;

use App\Filament\Resources\Integrations\Sqns\SqnsResource;
use App\Helpers\Actions\UpdateButton;
use App\Helpers\Traits\SyncAmoCRMPage;
use App\Jobs\Sqns\SyncVisits;
use App\Services\Sqns\Client;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Log;
use Throwable;

class EditSqns extends EditRecord
{
    use SyncAmoCRMPage;

    protected static string $resource = SqnsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UpdateButton::activeUpdate($this->record),

            UpdateButton::amoCRMSyncButton(
                $this->record->amoAccount(true, 'sqns'),
                fn () => $this->amocrmUpdate(),
            ),

            Action::make('connect_sqns')
                ->label('Подключить SQNS')
                ->icon('heroicon-o-link')
                ->action(fn () => $this->connectSqns()),

            Action::make('sync_visits')
                ->label('Загрузить визиты')
                ->icon('heroicon-o-arrow-path')
                ->disabled(fn (): bool => ! $this->record->isReadyToSync())
                ->tooltip(fn (): ?string => $this->record->isReadyToSync()
                    ? null
                    : 'Сначала подключите SQNS и настройте все этапы amoCRM.')
                ->form([
                    DatePicker::make('date_from')
                        ->label('С')
                        ->default(now()->subDays(30)->toDateString())
                        ->required(),
                    DatePicker::make('date_till')
                        ->label('По')
                        ->default(now()->addMonths(6)->toDateString())
                        ->afterOrEqual('date_from')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    SyncVisits::dispatch(
                        $this->record->id,
                        (string) $data['date_from'],
                        (string) $data['date_till'],
                    );

                    Notification::make()
                        ->title('Загрузка визитов поставлена в очередь')
                        ->success()
                        ->send();
                }),

            Action::make('history')
                ->label('История')
                ->icon('heroicon-o-list-bullet')
                ->url(SqnsResource::getUrl('visits')),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['token'], $data['webhook_secret'], $data['webhook_key']);
        $data['password'] = null;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['connection_state'], $data['pricing']);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        return $data;
    }

    public function connectSqns(): void
    {
        try {
            $this->save(false, false);
            $this->record->refresh();
            (new Client($this->record))->connect($this->record->webhookUrl());
            $this->refreshFormData(['organization_name', 'connected_at', 'last_error']);

            Notification::make()
                ->title('SQNS подключён, webhook зарегистрирован')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            $this->record->forceFill(['last_error' => $exception->getMessage()])->save();

            Log::error('SQNS connection failed.', [
                'setting_id' => $this->record->id,
                'error' => $exception->getMessage(),
            ]);

            Notification::make()
                ->title('Не удалось подключить SQNS')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }
}
