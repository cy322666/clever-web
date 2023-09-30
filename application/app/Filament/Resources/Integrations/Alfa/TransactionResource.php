<?php

namespace App\Filament\Resources\Integrations\Alfa;

use App\Filament\Resources\Integrations\Alfa\TransactionResource\Pages;
use App\Models\Integrations\Alfa\Setting;
use App\Models\Integrations\Alfa\Transaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class TransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (!Auth::user()->is_root) {

            $query->where('user_id', Auth::id());
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([

//                Tables\Columns\TextColumn::make('user.email')
//                    ->label('Клиент')
//                    ->searchable()
//                    ->sortable()
//                    ->hidden(fn() => !Auth::user()->is_root),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('amo_lead_id')
                    ->url(fn(Transaction $transaction) => 'https://'.$transaction->user->account->subdomain.'.amocrm.ru/leads/detail/'.$transaction->amo_lead_id, true)
                    ->label('Сделка'),

                Tables\Columns\TextColumn::make('amo_contact_id')
                    ->url(fn(Transaction $transaction) => 'https://'.$transaction->user->account->subdomain.'.amocrm.ru/contacts/detail/'.$transaction->amo_contact_id, true)
                    ->label('Контакт'),

                Tables\Columns\TextColumn::make('alfa_client_id')
                    ->url(fn(Transaction $transaction) => 'https://'.$transaction->user->alfacrm_settings->domain.'.s20.online/company/'.$transaction->alfa_branch_id.'/customer/view?id='.$transaction->alfa_client_id, true)
                    ->label('Клиент'),

//                Tables\Columns\TextColumn::make('alfa_branch_id')
//                    ->label('ID филиала'),

                Tables\Columns\TextColumn::make('alfa_lesson_id')
                    ->label('ID урока'),

                //TODO
                Tables\Columns\TextColumn::make('status')
                    ->label('Событие')
                    ->state(fn(Transaction $transaction) => match ($transaction->status) {
                        Setting::RECORD => 'Записан',
                        Setting::CAME => 'Пришел',
                        Setting::OMISSION => 'Не пришел',
                        default => '-',
                    }),
            ])
            ->defaultSort('created_at', 'DESC')
            ->filters([])
            ->actions([])
            ->paginated([30, 50, 'all'])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTransactions::route('/'),
        ];
    }
}
