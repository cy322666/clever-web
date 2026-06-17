<?php

namespace App\Filament\Resources\Integrations\YClients\Pages;

use App\Filament\Resources\Integrations\YClients\YClientsResource;
use App\Jobs\YClients\RecordSend;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;

class ListYClients extends ListRecords
{
    protected static string $resource = YClientsResource::class;

    protected static ?string $title = 'История записей';

    protected function getTableQuery(): ?Builder
    {
        return Record::query()
            ->with(['account', 'client'])
            ->where('user_id', Auth::user()->id);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('settings')
                ->label('Вернуться в настройки')
                ->icon('heroicon-o-arrow-left')
                ->url(function (): ?string {
                    $settingId = Setting::query()->where('user_id', Auth::id())->value('id');

                    return $settingId
                        ? YClientsResource::getUrl('edit', ['record' => $settingId])
                        : null;
                })
                ->visible(fn(): bool => Setting::query()->where('user_id', Auth::id())->exists()),

            Action::make('reexport_failed')
                ->label('Перевыгрузить ошибки')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->modalHeading('Перевыгрузить записи YClients с ошибками')
                ->modalSubmitActionLabel('Запустить')
                ->form([
                    DateTimePicker::make('from')
                        ->label('С')
                        ->seconds(false)
                        ->native(false)
                        ->required(),

                    DateTimePicker::make('to')
                        ->label('По')
                        ->seconds(false)
                        ->native(false)
                        ->required(),

                    Select::make('date_column')
                        ->label('Период считать по')
                        ->options([
                            'created_at' => 'Дата создания транзакции',
                            'updated_at' => 'Дата обновления транзакции',
                            'datetime' => 'Дата записи',
                        ])
                        ->default('created_at')
                        ->required()
                        ->native(false),

                    TextInput::make('company_id')
                        ->label('ID филиала')
                        ->numeric(),

                    TextInput::make('record_db_id')
                        ->label('ID транзакции')
                        ->helperText('Это колонка ID в текущем списке.')
                        ->numeric(),

                    TextInput::make('setting_id')
                        ->label('ID настройки')
                        ->numeric(),

                    TextInput::make('record_id')
                        ->label('ID записи YClients'),

                    TextInput::make('limit')
                        ->label('Лимит')
                        ->numeric()
                        ->minValue(1)
                        ->default(1000)
                        ->required(),

                    Toggle::make('include_pending')
                        ->label('Захватить все неуспешные, включая pending')
                        ->helperText('По умолчанию берём только status=failed или записи с текстом ошибки.'),

                    Toggle::make('with_notes')
                        ->label('Создавать примечания повторно')
                        ->helperText('Лучше оставлять выключенным, чтобы не плодить дубли примечаний.'),
                ])
                ->action(fn(array $data) => $this->reexportFailedRecords($data)),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([

                TextColumn::make('id')
                    ->label('ID'),

                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                TextColumn::make('company_id')
                    ->label('ID филиала')
                    ->searchable(),

                TextColumn::make('record_id')
                    ->label('ID записи')
                    ->searchable(),

                TextColumn::make('staff_name')
                    ->label('Специалист')
                    ->hidden()
                    ->searchable(),

                TextColumn::make('client.name')
                    ->hidden()
                    ->label('Клиент'),

                TextColumn::make('client_id')
                    ->label('ID клиента'),

                TextColumn::make('lead_id')
                    ->url(fn(Record $order) => 'https://'.$order->account->subdomain.'.amocrm.ru/leads/detail/'.$order->lead_id, true)
                    ->label('Сделка')
                    ->searchable(),

//                TextColumn::make('client.contact_id')
//                    ->url(fn(Record $order) => 'https://'.$order->account->subdomain.'.amocrm.ru/contacts/detail/'.$order->lead_id, true)
//                    ->label('Контакт'),

                TextColumn::make('title')
                    ->hidden()
                    ->label('Название'),

                TextColumn::make('cost')
                    ->label('Стоимость'),

                TextColumn::make('attendance')
                    ->label('Событие')
                    ->state(fn(Record $record): string => $record->getEvent()),

                IconColumn::make('status')
                    ->label('Выгружен')
                    ->state(fn(Record $record): bool => (string)$record->status === Record::STATUS_SUCCESS)
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->alignCenter(),

                TextColumn::make('error_message')
                    ->label('Ошибка')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->wrap(),
            ])
            ->recordUrl(null)
            ->defaultSort('created_at', 'desc')
            ->paginated([50, 100])
            ->defaultPaginationPageOption(50)
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        Record::STATUS_SUCCESS => 'Успешно',
                        Record::STATUS_FAILED => 'Ошибка',
                        Record::STATUS_PENDING => 'В очереди',
                        'with_error_message' => 'Есть текст ошибки',
                        'not_success' => 'Не успешно',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['value'] ?? null,
                            function (Builder $query, string $value): Builder {
                                return match ($value) {
                                    Record::STATUS_SUCCESS => $query->where('status', Record::STATUS_SUCCESS),
                                    Record::STATUS_FAILED => $query->where('status', Record::STATUS_FAILED),
                                    Record::STATUS_PENDING => $query->where('status', Record::STATUS_PENDING),
                                    'with_error_message' => $query->whereNotNull('error_message'),
                                    default => $query->where(function (Builder $query): Builder {
                                        return $query
                                            ->where('status', '!=', Record::STATUS_SUCCESS)
                                            ->orWhereNull('status');
                                    }),
                                };
                            }
                        );
                    }),
            ])
            ->actions([])
            ->bulkActions([
                BulkAction::make('dispatched')
                    ->action(function (Collection $collection) {

                        $collection->each(function (Record $form) {
                            RecordSend::dispatch($form, $form->account, $form->setting, false);
                        });
                    })
                    ->label('Выгрузить')
            ])
            ->emptyStateActions([]);
    }

    private function reexportFailedRecords(array $data): void
    {
        $dateColumn = in_array($data['date_column'] ?? null, ['created_at', 'updated_at', 'datetime'], true)
            ? $data['date_column']
            : 'created_at';

        $query = Record::query()
            ->with(['account', 'setting'])
            ->where('user_id', Auth::id())
            ->failedExport((bool)($data['include_pending'] ?? false))
            ->where($dateColumn, '>=', Carbon::parse($data['from']))
            ->where($dateColumn, '<=', Carbon::parse($data['to']));

        foreach (['company_id', 'setting_id', 'record_id'] as $column) {
            if (!blank($data[$column] ?? null)) {
                $query->where($column, $data[$column]);
            }
        }

        if (!blank($data['record_db_id'] ?? null)) {
            $query->where('id', $data['record_db_id']);
        }

        $records = $query
            ->orderBy($dateColumn)
            ->orderBy('id')
            ->limit(max(1, (int)($data['limit'] ?? 1000)))
            ->get();

        $queued = 0;
        $failed = 0;

        foreach ($records as $record) {
            if (!$record->account || !$record->setting) {
                $failed++;
                $record->forceFill([
                    'status' => Record::STATUS_FAILED,
                    'error_message' => 'YClients re-export skipped: account or setting not found.',
                ])->save();

                continue;
            }

            $record->forceFill([
                'status' => Record::STATUS_PENDING,
                'error_message' => null,
            ])->save();

            RecordSend::dispatch(
                $record->fresh(),
                $record->account,
                $record->setting,
                (bool)($data['with_notes'] ?? false),
            );

            $queued++;
        }

        $notification = Notification::make()
            ->title($queued > 0 ? 'Перевыгрузка запущена' : 'Ошибочных записей не найдено')
            ->body(sprintf('Поставлено в очередь: %d. Пропущено с ошибкой настройки: %d.', $queued, $failed));

        ($failed > 0 ? $notification->warning() : $notification->success())->send();
    }
}
