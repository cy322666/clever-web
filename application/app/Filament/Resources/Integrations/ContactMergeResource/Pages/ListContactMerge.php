<?php

namespace App\Filament\Resources\Integrations\ContactMergeResource\Pages;

use App\Filament\Resources\Integrations\ContactMergeResource;
use App\Models\Integrations\ContactMerge\Record;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListContactMerge extends ListRecords
{
    protected static string $resource = ContactMergeResource::class;

    protected static ?string $title = 'История склейки контактов';

    protected function getTableQuery(): ?Builder
    {
        return Record::query()->where('user_id', Auth::id());
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('master_contact_id')
                    ->label('Главный контакт')
                    ->url(fn (Record $record) => $this->contactUrl($record, $record->master_contact_id), true),

                TextColumn::make('duplicate_contact_id')
                    ->label('Дубль')
                    ->url(fn (Record $record) => $this->contactUrl($record, $record->duplicate_contact_id), true),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (Record $record): string => match ($record->status) {
                        'merged' => 'success',
                        'error' => 'danger',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'merged' => 'Склеено',
                        'error' => 'Ошибка',
                        default => 'Помечено',
                    }),

                TextColumn::make('changes')
                    ->label('Изменения')
                    ->state(function (Record $record): string {
                        $changes = $record->changes ?? [];

                        if (!$changes) {
                            return 'Без изменений';
                        }

                        return 'Изменено полей: '.count($changes);
                    }),

                TextColumn::make('message')
                    ->label('Сообщение')
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([20, 40, 'all'])
            ->recordUrl(null)
            ->filters([])
            ->actions([])
            ->bulkActions([])
            ->emptyStateActions([]);
    }

    private function contactUrl(Record $record, int $contactId): string
    {
        $account = $record->setting?->user?->account;
        $zone = $account?->zone ?? 'ru';

        if (!$account?->subdomain) {
            return '#';
        }

        return 'https://'.$account->subdomain.'.amocrm.'.$zone.'/contacts/detail/'.$contactId;
    }
}
