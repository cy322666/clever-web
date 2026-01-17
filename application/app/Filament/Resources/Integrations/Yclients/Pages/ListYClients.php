<?php

namespace App\Filament\Resources\Integrations\YClients\Pages;

use App\Filament\Resources\Integrations\YClients\YClientsResource;
use App\Jobs\YClients\RecordSend;
use App\Models\Integrations\YClients\Record;
use Filament\Actions\BulkAction;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Columns\BooleanColumn;
use Filament\Tables\Columns\TextColumn;
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
        return Record::query()->where('user_id', Auth::user()->id);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([

                TextColumn::make('id')
                    ->label('ID'),

                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('company_id')
                    ->label('ID филиала')
                    ->searchable(),

                TextColumn::make('record_id')
                    ->label('ID записи')
                    ->searchable(),

                TextColumn::make('staff_name')
                    ->label('Специалист')
                    ->searchable(),

                TextColumn::make('client.name')
                    ->label('Клиент'),

                TextColumn::make('client_id')
                    ->label('ID клиента'),

                TextColumn::make('lead_id')
                    ->url(fn(Record $order) => 'https://'.$order->account->subdomain.'.amocrm.ru/leads/detail/'.$order->lead_id, true)
                    ->label('Сделка'),

                TextColumn::make('contact_id')
                    ->url(fn(Record $order) => 'https://'.$order->account->subdomain.'.amocrm.ru/contacts/detail/'.$order->lead_id, true)
                    ->label('Контакт'),

                BooleanColumn::make('status')
                    ->label('Выгружен'),

                TextColumn::make('attendance')
                    ->label('Событие')
                    ->state(fn(Record $record): string => $record->getEvent()),

                TextColumn::make('title')
                    ->label('Название'),

                TextColumn::make('cost')
                    ->label('Стоимость'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([20, 40, 'all'])
            ->poll('5s')
            ->filters([])
            ->actions([])
            ->bulkActions([
                BulkAction::make('dispatched')
                    ->action(function (Collection $collection) {

                        $collection->each(function (Record $form) {

                            RecordSend::dispatch($form, $form->account, $form->setting);
                        });
                    })
                    ->label('Выгрузить')
            ])
            ->emptyStateActions([]);
    }
}
