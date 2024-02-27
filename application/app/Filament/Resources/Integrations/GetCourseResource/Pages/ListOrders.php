<?php

namespace App\Filament\Resources\Integrations\Tilda\FormResource\Pages;

use App\Filament\Resources\Integrations\GetCourseResource;
use App\Filament\Resources\Integrations\Tilda\FormResource;
use App\Jobs\GetCourse\OrderSend;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\BooleanColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use App\Filament\Resources\Integrations\GetCourseResource\Pages;
use App\Helpers\Traits\SettingResource;
use App\Helpers\Traits\TenantResource;
use App\Jobs\Tilda\FormSend;
use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\Integrations\GetCourse;
use App\Models\Integrations\Tilda\Form;
use Filament\Forms;
use Filament\Resources\Resource;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ListOrders extends ListRecords
{
    protected static string $resource = GetCourseResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected static ?string $title = 'История заказов';

    protected function getTableQuery(): ?Builder
    {
        return GetCourse\Order::query()->where('user_id', Auth::user()->id);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([

                TextColumn::make('id')
                    ->label('ID'),

                TextColumn::make('user.email')
                    ->label('Клиент')
                    ->searchable()
                    ->sortable()
                    ->hidden(fn() => !Auth::user()->is_root),

                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('lead_id')
                    ->url(fn(GetCourse\Order $order) => 'https://'.$order->user->account->subdomain.'.amocrm.ru/leads/detail/'.$order->lead_id, true)
                    ->label('Сделка'),

                TextColumn::make('contact_id')
                    ->url(fn(GetCourse\Order $order) => 'https://'.$order->user->account->subdomain.'.amocrm.ru/contacts/detail/'.$order->lead_id, true)
                    ->label('Контакт'),

                BooleanColumn::make('status')
                    ->label('Выгружен'),

                TextColumn::make('site')
                    ->label('Форма'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([20, 40, 'all'])
            ->poll('15s')
            ->filters([])
            ->actions([])
            ->bulkActions([
                BulkAction::make('dispatched')
                    ->action(function (Collection $collection) {

                        $collection->each(function (GetCourse\Order $order) {

                            OrderSend::dispatch($order, $order->user->account, $order->setting);
                        });
                    })
                    ->label('Выгрузить')
            ])
            ->emptyStateActions([]);
    }
}
