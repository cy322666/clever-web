<?php

namespace App\Filament\Resources\Integrations\ImportExcel\ImportResource\Pages;

use App\Filament\Resources\Integrations\ImportExcel\ImportResource;
use App\Jobs\ImportExcel\ProcessImportRow;
use App\Models\Integrations\ImportExcel\ImportRecord;
use Filament\Actions\BulkAction as ActionsBulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions\BulkAction;
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

                TextColumn::make('row_data')
                    ->label('Строка')
                    ->formatStateUsing(fn($state) => json_encode($state, JSON_UNESCAPED_UNICODE))

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
//                    ->icon('heroicon-o-play')
                    ->action(function (Collection $records): void {
                        $count = 0;
                        foreach ($records as $record) {

                            $record->delete();
                        }
                        Notification::make()
                            ->title('Удаление запущено')
                            ->body("Удалено записей : {$count}.")
                            ->success()
                            ->send();
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
}
