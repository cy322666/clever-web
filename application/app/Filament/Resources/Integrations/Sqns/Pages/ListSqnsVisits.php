<?php

namespace App\Filament\Resources\Integrations\Sqns\Pages;

use App\Filament\Resources\Integrations\Sqns\SqnsResource;
use App\Jobs\Sqns\SyncVisitToAmo;
use App\Models\Integrations\Sqns\Setting;
use App\Models\Integrations\Sqns\Visit;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListSqnsVisits extends ListRecords
{
    protected static string $resource = SqnsResource::class;

    protected static ?string $title = 'История визитов SQNS';

    protected function getTableQuery(): ?Builder
    {
        return Visit::query()
            ->with(['account'])
            ->where('user_id', Auth::id());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('settings')
                ->label('Вернуться в настройки')
                ->icon('heroicon-o-arrow-left')
                ->url(function (): ?string {
                    $settingId = Setting::query()->where('user_id', Auth::id())->value('id');

                    return $settingId ? SqnsResource::getUrl('edit', ['record' => $settingId]) : null;
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('created_at')->label('Получен')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('visit_id')->label('Визит SQNS')->searchable(),
                TextColumn::make('client_id')->label('Клиент SQNS')->searchable(),
                TextColumn::make('datetime')->label('Дата визита')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('services')->label('Услуги')->wrap()->limit(60),
                TextColumn::make('cost')->label('Стоимость'),
                TextColumn::make('attendance')
                    ->label('Событие')
                    ->state(fn (Visit $record): string => $record->eventLabel()),
                TextColumn::make('lead_id')
                    ->label('Сделка')
                    ->url(fn (Visit $record): ?string => $record->lead_id && $record->account
                        ? 'https://'.$record->account->subdomain.'.amocrm.ru/leads/detail/'.$record->lead_id
                        : null, true),
                IconColumn::make('status')
                    ->label('Выгружен')
                    ->state(fn (Visit $record): bool => $record->status === Visit::STATUS_SUCCESS)
                    ->boolean()
                    ->alignCenter(),
                TextColumn::make('error_message')
                    ->label('Ошибка')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordUrl(null)
            ->defaultSort('created_at', 'desc')
            ->paginated([50, 100])
            ->defaultPaginationPageOption(50)
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        Visit::STATUS_SUCCESS => 'Успешно',
                        Visit::STATUS_FAILED => 'Ошибка',
                        Visit::STATUS_PENDING => 'В очереди',
                    ]),
            ])
            ->recordActions([
                Action::make('retry')
                    ->label('Повторить')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (Visit $record): bool => $record->status !== Visit::STATUS_SUCCESS)
                    ->action(function (Visit $record): void {
                        $record->forceFill([
                            'status' => Visit::STATUS_PENDING,
                            'error_message' => null,
                        ])->save();
                        SyncVisitToAmo::dispatch($record->id, false);

                        Notification::make()->title('Визит поставлен в очередь')->success()->send();
                    }),
            ]);
    }
}
