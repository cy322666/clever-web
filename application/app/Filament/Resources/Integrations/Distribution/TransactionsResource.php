<?php

namespace App\Filament\Resources\Integrations\Distribution;

use App\Filament\Resources\Integrations\Distribution\TransactionsResource\Pages;
use App\Jobs\Tilda\FormSend;
use App\Models\Integrations\Distribution\Setting;
use App\Models\Integrations\Distribution\Transaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class TransactionsResource extends Resource
{
    protected static ?string $model = Transaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_id', Auth::id());
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([

                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->hidden(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Тип распределения')
                    ->formatStateUsing(function (Transaction $transaction) {

                        return match ($transaction->type) {
                            Setting::STRATEGY_SCHEDULE => 'График',
                            Setting::STRATEGY_ROTATION => 'По очереди',
                            Setting::STRATEGY_RANDOM   => 'Вразброс',
                        };
                    }),

                Tables\Columns\TextColumn::make('staff_name')
                    ->label('Ответственный'),

                Tables\Columns\TextColumn::make('staff_amocrm_id')
                    ->label('id'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('lead_id')
                    ->url(fn(Transaction $transaction) => 'https://'.$transaction->user->account->subdomain.'.amocrm.ru/leads/detail/'.$transaction->lead_id, true)
                    ->label('Сделка'),

                Tables\Columns\TextColumn::make('contact_id')
                    ->url(fn(Transaction $transaction) => 'https://'.$transaction->user->account->subdomain.'.amocrm.ru/contacts/detail/'.$transaction->lead_id, true)
                    ->label('Контакт'),

                Tables\Columns\BooleanColumn::make('status')
                    ->label('Успешно'),

                Tables\Columns\TextColumn::make('template')
                    ->label('Шаблон'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([20, 40, 'all'])
            ->poll('15s')
            ->filters([])
            ->actions([])
            ->bulkActions([
                Tables\Actions\BulkAction::make('dispatched')
                    ->action(function (Collection $collection) {

                        $collection->each(function (Transaction $transaction) {

                            $user    = $transaction->user;
                            $setting = $user->tilda_settings;

                            sleep(2);

                            //TODO need?
//                            FormSend::dispatch($transaction, $user->account, $setting);
                        });
                    })
                    ->label('Выгрузить')
            ])
            ->emptyStateActions([]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTransactions::route('/'),
        ];
    }
}
