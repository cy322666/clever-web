<?php

namespace App\Filament\Resources\Integrations\ImportExcel\ImportResource\Pages;

use App\Filament\Resources\Integrations\ImportExcel\ImportResource;
use App\Models\Integrations\ImportExcel\ImportRecord;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
                    ->formatStateUsing(fn(string $state) => match ($state) {
                        ImportRecord::STATUS_COMPLETED => 'Завершен',
                        ImportRecord::STATUS_FAILED => 'Ошибка',
                        default => 'В процессе',
                    }),

//                Progress::make('progress')
//                    ->label('Прогресс')
//                    ->formatStateUsing(fn (ImportRecord $record): string =>
//                        $record->processed_rows . ' / ' . $record->total_rows
//                    ),

                TextColumn::make('success_rows')
                    ->label('Успешно')
                    ->badge()
                    ->color('success'),

                TextColumn::make('error_rows')
                    ->label('Ошибок')
                    ->badge()
                    ->color('danger')
                    ->visible(fn(?ImportRecord $record) => $record?->error_rows > 0),

                TextColumn::make('error_message')
                    ->label('Ошибка')
                    ->wrap()
                    ->toggleable()
                    ->visible(fn(?ImportRecord $record) => !empty($record->error_message)),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([20, 40, 'all'])
            ->recordUrl(null)
            ->filters([])
            ->actions([])
            ->bulkActions([])
            ->emptyStateActions([]);
    }

    protected function getTableQuery(): ?Builder
    {
        return ImportRecord::query()
            ->where('user_id', Auth::id())
            ->with('import');
    }
}
