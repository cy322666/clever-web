<?php

namespace App\Filament\Resources\Integrations\CallTranscriptionResource\Pages;

use App\Filament\Resources\Integrations\CallTranscriptionResource;
use App\Models\Integrations\CallTranscription\Transaction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListTransactions extends ListRecords
{
    protected static string $resource = CallTranscriptionResource::class;

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime()
                    ->sortable(),

//                Tables\Columns\TextColumn::make('lead_id')
//                    ->label('Сделка')
//                    ->url(fn (Transaction $transaction) => 'https://' . $transaction->user->account->subdomain . '.amocrm.ru/leads/detail/' . $transaction->lead_id, true),

                Tables\Columns\TextColumn::make('contact_id')
                    ->label('Контакт')
                    ->url(
                        fn(Transaction $transaction
                        ) => 'https://' . $transaction->user->account->subdomain . '.amocrm.ru/contacts/detail/' . $transaction->contact_id,
                        true
                    ),

                Tables\Columns\TextColumn::make('duration')
                    ->label('Длит')
                    ->sortable(),

                Tables\Columns\TextColumn::make('note_type')
                    ->label('Тип')
                    ->state(fn(Transaction $transaction) => $transaction->note_type == 11 ? 'Исходящий' : 'Входящий')
                    ->badge(),

//                Tables\Columns\TextColumn::make('status')
//                    ->label('Статус обработки')
//                    ->badge(),

                Tables\Columns\TextColumn::make('text')
                    ->label('Текст')
                    ->limit(30)
                    ->tooltip(fn(Transaction $transaction) => $transaction->text)
                    ->wrap(),

                Tables\Columns\TextColumn::make('result')
                    ->label('Итог')
                    ->limit(30)
                    ->tooltip(fn(Transaction $transaction) => $transaction->result)
                    ->wrap(),

                Tables\Columns\TextColumn::make('url')
                    ->label('Запись')
                    ->formatStateUsing(fn() => '')
                    ->url(fn(Transaction $transaction) => $transaction->url, true)
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->tooltip(fn(Transaction $transaction) => $transaction->url),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([30, 50, 'all'])
            ->filters([])
            ->actions([])
            ->bulkActions([])
            ->emptyStateActions([]);
    }

    protected function getTableQuery(): ?Builder
    {
        $query = Transaction::query();

        if (!Auth::user()->is_root) {
            $query->where('user_id', Auth::id());
        }

        return $query;
    }


    protected function getHeaderActions(): array
    {
        return [];
    }
}
