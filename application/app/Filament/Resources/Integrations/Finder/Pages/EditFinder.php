<?php

namespace App\Filament\Resources\Integrations\Finder\Pages;

use App\Filament\Resources\Core\UserResource;
use App\Filament\Resources\Integrations\Finder\FinderResource;
use App\Services\Finder\MonitoringState;
use App\Services\Finder\SettingsValidator;
use App\Services\Finder\WebhookConnection;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;
use Throwable;

class EditFinder extends EditRecord
{
    protected static string $resource = FinderResource::class;

    protected static ?string $title = 'Finder';

    protected function getHeaderActions(): array
    {
        $enabled = $this->record->isMonitoringEnabled();
        $account = $this->record->amoAccount();
        $zone = trim((string) ($account?->zone ?: 'ru'));
        $domain = $account?->subdomain.'.'.(str_contains($zone, '.') ? $zone : 'amocrm.'.$zone);

        return [
            Action::make('active')->label(fn () => $this->record->isMonitoringEnabled() ? 'Выключить' : 'Включить')
                ->color(fn () => $this->record->isMonitoringEnabled() ? 'danger' : 'success')
                ->disabled(fn () => ! $this->record->isMonitoringEnabled() && ! $account?->active)
                ->tooltip($account?->active ? null : 'Сначала подключите amoCRM в аккаунте платформы')
                ->action(function () use ($enabled): void {
                    $data = $enabled ? [] : $this->form->getState();
                    try {
                        $this->record = app(MonitoringState::class)->setEnabled($this->record, ! $enabled,
                            $enabled ? null : array_replace($this->record->options(), $data['settings'] ?? []));
                    } catch (ValidationException $exception) {
                        if (collect(array_keys($exception->errors()))->contains(fn ($field) => str_starts_with($field, 'settings.'))) {
                            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $field) => ['data.'.$field => $messages])->all());
                        }
                        Notification::make()->title('Не удалось включить Finder')->body($exception->getMessage())->danger()->send();

                        return;
                    }
                    Notification::make()->title($enabled ? 'Finder выключен' : 'Finder включён')->success()->send();
                }),
            $account?->active
                ? Action::make('references')->label($domain)->icon('heroicon-o-arrow-path')->color('gray')
                    ->tooltip('Общее подключение amoCRM. Обновить сотрудников и типы задач')->action(function (): void {
                        try {
                            app(\App\Services\Workflows\WorkflowNodeReferences::class)->refresh('amocrm_create_task', []);
                            Notification::make()->title('Сотрудники и типы задач обновлены')->success()->send();
                        } catch (Throwable) {
                            Notification::make()->title('Не удалось обновить справочники amoCRM')->danger()->send();
                        }
                    })
                : Action::make('account')->label('Подключить amoCRM')->icon('heroicon-o-key')
                    ->url(UserResource::getUrl('view', ['record' => $this->record->user_id])),
            Action::make('connect')->label('Подключить сообщения')->icon('heroicon-o-link')->action(function (): void {
                $this->save(false, false);
                try {
                    app(WebhookConnection::class)->connect($this->record->refresh());
                    Notification::make()->title('Оба события сообщений подключены')->success()->send();
                    $this->refreshFormData(['connected_at']);
                } catch (ValidationException $exception) {
                    Notification::make()->title('Не удалось подключить сообщения')->body($exception->getMessage())->danger()->send();
                } catch (Throwable) {
                    Notification::make()->title('amoCRM не подтвердил подключение. Проверьте авторизацию и повторите.')->danger()->send();
                }
            }),
            Action::make('history')->label('История')->icon('heroicon-o-list-bullet')->url(FinderResource::getUrl('history')),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['settings'] = $this->record->options();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Hidden fields retain defaults; server validation scopes scenario/staff IDs.
        try {
            $data['settings'] = app(SettingsValidator::class)->validate(
                array_replace($this->record->options(), $data['settings'] ?? []), (int) $this->record->user_id, $this->record->isMonitoringEnabled(),
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $field) => ['data.'.$field => $messages])->all());
        }

        return ['settings' => $data['settings']];
    }
}
