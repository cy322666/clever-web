<?php

namespace App\Filament\Resources\Integrations\Finder\Pages;

use App\Filament\Resources\Integrations\Finder\FinderResource;
use App\Helpers\Actions\UpdateButton;
use App\Models\Integrations\Finder\Action as FinderAction;
use App\Models\Integrations\Finder\Conversation;
use App\Services\Finder\SettingsValidator;
use App\Services\Finder\WebhookConnection;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class EditFinder extends EditRecord
{
    protected static string $resource = FinderResource::class;

    protected static ?string $title = 'Finder';

    protected function getHeaderActions(): array
    {
        return [
            UpdateButton::activeUpdate($this->record),
            Action::make('references')->label('amoCRM')->icon('heroicon-o-arrow-path')->color('gray')
                ->tooltip('Обновить сотрудников и типы задач')->action(function (): void {
                    try {
                        app(\App\Services\Workflows\WorkflowNodeReferences::class)->refresh('amocrm_create_task', []);
                        Notification::make()->title('Сотрудники и типы задач обновлены')->success()->send();
                    } catch (Throwable) {
                        Notification::make()->title('Не удалось обновить справочники amoCRM')->danger()->send();
                    }
                }),
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
                array_replace($this->record->options(), $data['settings'] ?? []), (int) $this->record->user_id, (bool) $data['enabled'],
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $field) => ['data.'.$field => $messages])->all());
        }

        return ['enabled' => (bool) $data['enabled'], 'settings' => $data['settings']];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $record = $record->newQuery()->lockForUpdate()->findOrFail($record->id);
            $record->update($data);
            if (! $record->enabled) {
                Conversation::query()->where('setting_id', $record->id)->update(['pending_since' => null, 'next_check_at' => null]);
                FinderAction::query()->where('setting_id', $record->id)->where('status', 'pending')->update(['status' => 'cancelled']);
            }

            return $record;
        });
    }
}
