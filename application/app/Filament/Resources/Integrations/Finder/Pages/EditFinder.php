<?php

namespace App\Filament\Resources\Integrations\Finder\Pages;

use App\Filament\Resources\Integrations\Finder\FinderResource;
use App\Services\Finder\MonitoringState;
use App\Services\Finder\SettingsValidator;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;
use Throwable;

class EditFinder extends EditRecord
{
    protected static string $resource = FinderResource::class;

    protected static ?string $title = 'Контроль ответов';

    protected function getHeaderActions(): array
    {
        $enabled = $this->record->isMonitoringEnabled();
        $account = $this->record->amoAccount();
        $zone = trim((string) ($account?->zone ?: 'ru'));
        $domain = $account?->subdomain ? $account->subdomain.'.'.(str_contains($zone, '.') ? $zone : 'amocrm.'.$zone) : null;
        $authorizationUrl = $this->amoAuthorizationUrl();

        return [
            Action::make('active')->label(fn () => $this->record->isMonitoringEnabled() ? 'Выключить' : 'Включить')
                ->color(fn () => $this->record->isMonitoringEnabled() ? 'danger' : 'success')
                ->disabled(fn () => ! $this->record->isMonitoringEnabled() && ! $account?->active)
                ->tooltip($account?->active ? null : 'Сначала подключите amoCRM')
                ->action(function () use ($enabled): void {
                    $data = $enabled ? [] : $this->form->getState();
                    try {
                        $this->record = app(MonitoringState::class)->setEnabled($this->record, ! $enabled,
                            $enabled ? null : array_replace($this->record->options(), $data['settings'] ?? []));
                    } catch (ValidationException $exception) {
                        if (collect(array_keys($exception->errors()))->contains(fn ($field) => str_starts_with($field, 'settings.'))) {
                            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $field) => ['data.'.$field => $messages])->all());
                        }
                        Notification::make()->title('Не удалось включить контроль ответов')->body($exception->getMessage())->danger()->send();

                        return;
                    }
                    Notification::make()->title($enabled ? 'Контроль ответов выключен' : 'Контроль ответов включён')->success()->send();
                }),
            Action::make('account')->label($domain ?: 'Подключить amoCRM')->icon('heroicon-o-key')->color('gray')
                ->url($authorizationUrl)->disabled($authorizationUrl === null)
                ->tooltip($authorizationUrl === null ? 'Подключение amoCRM пока не настроено' : 'Подключить amoCRM к виджету «Контроль ответов»'),
            Action::make('references')->label('Обновить сотрудников и типы задач')->icon('heroicon-o-arrow-path')->iconButton()->color('gray')
                    ->visible((bool) $account?->active)->tooltip('Обновить сотрудников и типы задач')->action(function (): void {
                        try {
                            app(\App\Services\Workflows\WorkflowNodeReferences::class)->refresh('amocrm_create_task', []);
                            Notification::make()->title('Сотрудники и типы задач обновлены')->success()->send();
                        } catch (Throwable) {
                            Notification::make()->title('Не удалось обновить справочники amoCRM')->danger()->send();
                        }
                    }),
            Action::make('history')->label('История')->icon('heroicon-o-list-bullet')->url(FinderResource::getUrl('history')),
        ];
    }

    private function amoAuthorizationUrl(): ?string
    {
        $clientId = trim((string) config('services.amocrm.widgets.finder.client_id'));
        if ($clientId === '') {
            return null;
        }

        $state = app(\App\Services\Finder\InstallationContext::class)->encode($this->record);

        return 'https://www.amocrm.ru/oauth/?'.http_build_query([
            'client_id' => $clientId,
            'state' => $state,
            'uri' => FinderResource::getUrl('edit', ['record' => $this->record]),
        ], '', '&', PHP_QUERY_RFC3986);
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
