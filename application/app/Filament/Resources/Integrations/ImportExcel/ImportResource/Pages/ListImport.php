<?php

namespace App\Filament\Resources\Integrations\ImportExcel\ImportResource\Pages;

use App\Filament\Resources\Integrations\ImportExcel\ImportResource;
use App\Jobs\ImportExcel\ProcessImportRow;
use App\Models\Integrations\ImportExcel\ImportRecord;
use App\Models\Integrations\ImportExcel\ImportSetting;
use Filament\Actions\Action;
use Filament\Actions\BulkAction as ActionsBulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions\BulkAction;
use Filament\Tables\Columns\BooleanColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

class ListImport extends ListRecords
{
    protected static string $resource = ImportResource::class;

    protected static ?string $title = 'История импорта';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Дата импорта')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('filename')
                    ->label('Файл')
                    ->searchable(),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn(ImportRecord $record): string => match ($record->status) {
                        ImportRecord::STATUS_COMPLETED => 'success',
                        ImportRecord::STATUS_FAILED => 'danger',
                        default => 'warning',
                    })
                    ->sortable()
                    ->formatStateUsing(fn(string $state) => match ($state) {
                        ImportRecord::STATUS_COMPLETED => 'Завершен',
                        ImportRecord::STATUS_FAILED => 'Ошибка',
                        default => 'В процессе',
                    }),

                TextColumn::make('lead_id')
                    ->label('Сделка')
                    ->url(
                        fn(ImportRecord $order
                        ) => 'https://' . $order->user->account->subdomain . '.amocrm.ru/leads/detail/' . $order->lead_id,
                        true
                    ),

                TextColumn::make('contact_id')
                    ->label('Контакт')
                    ->url(
                        fn(ImportRecord $order
                        ) => 'https://' . $order->user->account->subdomain . '.amocrm.ru/contacts/detail/' . $order->contact_id,
                        true
                    ),

                TextColumn::make('company_id')
                    ->label('Компания')
                    ->url(
                        fn(ImportRecord $order
                        ) => 'https://' . $order->user->account->subdomain . '.amocrm.ru/companies/detail/' . $order->company_id,
                        true
                    ),

                TextColumn::make('row_data') // имя колонки в БД, где лежит JSON-строка
                ->label('Строка')
                    ->wrap()
//                    ->lineClamp(2)        // 👈 по умолчанию свернуто (2 строки)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->state(function (ImportRecord $record) {
                        $data = $record->row_data;

                        if (is_array($data)) {
                            return json_encode($data, JSON_UNESCAPED_UNICODE);
                        }

                        return (string)$data;
                    })
                    ->wrap(),

                BooleanColumn::make('searched_contact')
                    ->label('Найден контакт'),

                BooleanColumn::make('searched_company')
                    ->label('Найдена компания'),

            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([50, 'all'])
            ->recordUrl(null)
            ->poll(5)
            ->filters([])
            ->bulkActions([
                ActionsBulkAction::make('run_export')
                    ->label('Запустить выгрузку')
                    ->icon('heroicon-o-play')
                    ->action(function (Collection $records): void {
                        $count = 0;
                        foreach ($records as $record) {
                            if ($record instanceof ImportRecord && $record->import_id && $record->row_data) {
                                ProcessImportRow::dispatch($record->import_id, $record->id);
                                $count++;
                            }
                        }
                        Notification::make()
                            ->title('Выгрузка запущена')
                            ->body("В очередь добавлено записей: {$count}.")
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),

                ActionsBulkAction::make('delete')
                    ->label('Отменить выгрузку')
                    ->action(function (Collection $records): void {
                        foreach ($records as $record) {
                            $record->delete();
                        }
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->emptyStateActions([]);
    }

    protected function getTableQuery(): ?Builder
    {
        return ImportRecord::query()
            ->where('user_id', Auth::id())
            ->with('import');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label('Выгрузить все')
                ->action(function (ImportSetting $setting) {
                    $records = ImportRecord::query()
                        ->where('')
                        ->get();

                    foreach ($records as $record) {
                        ProcessImportRow::dispatch($this->setting->id, $setting->id);
                    }
                })
                ->color('primary'),
        ];
    }
}
