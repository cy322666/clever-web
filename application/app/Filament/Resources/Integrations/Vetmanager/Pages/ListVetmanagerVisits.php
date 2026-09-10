<?php

namespace App\Filament\Resources\Integrations\Vetmanager\Pages;

use App\Filament\Resources\Integrations\Vetmanager\VetmanagerResource;
use App\Jobs\Vetmanager\SyncVisit;
use App\Models\Integrations\Vetmanager\Setting;
use App\Models\Integrations\Vetmanager\Visit;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListVetmanagerVisits extends ListRecords
{
    protected static string $resource = VetmanagerResource::class;

    protected static ?string $title = 'История посещений Vetmanager';

    protected function getTableQuery(): ?Builder
    {
        return Visit::query()
            ->with('account')
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

                    return $settingId ? VetmanagerResource::getUrl('edit', ['record' => $settingId]) : null;
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Получен')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('external_id')->label('Прием')->searchable(),
                TextColumn::make('admission_date')->label('Дата приема')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('client_name')->label('Клиент')->searchable()->placeholder('Не загружен'),
                TextColumn::make('patient_name')->label('Питомец')->searchable()->placeholder('Не загружен'),
                TextColumn::make('amount')->label('Сумма')->money('RUB')->placeholder('—'),
                TextColumn::make('contact_id')
                    ->label('Контакт')
                    ->url(fn (Visit $record): ?string => $this->amoUrl($record, 'contacts', $record->contact_id), true),
                TextColumn::make('lead_id')
                    ->label('Сделка')
                    ->url(fn (Visit $record): ?string => $this->amoUrl($record, 'leads', $record->lead_id), true),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Visit::STATUS_SUCCESS => 'Готово',
                        Visit::STATUS_FAILED => 'Ошибка',
                        Visit::STATUS_PROCESSING => 'В работе',
                        default => 'В очереди',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        Visit::STATUS_SUCCESS => 'success',
                        Visit::STATUS_FAILED => 'danger',
                        Visit::STATUS_PROCESSING => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('error_message')
                    ->label('Ошибка')
                    ->wrap()
                    ->limit(100)
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
                        Visit::STATUS_PENDING => 'В очереди',
                        Visit::STATUS_PROCESSING => 'В работе',
                        Visit::STATUS_SUCCESS => 'Готово',
                        Visit::STATUS_FAILED => 'Ошибка',
                    ]),
            ])
            ->recordActions([
                Action::make('retry')
                    ->label('Повторить')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (Visit $record): bool => $record->status === Visit::STATUS_FAILED)
                    ->action(function (Visit $record): void {
                        $record->forceFill([
                            'status' => Visit::STATUS_PENDING,
                            'error_message' => null,
                        ])->save();
                        SyncVisit::dispatch($record->id);

                        Notification::make()->title('Посещение поставлено в очередь')->success()->send();
                    }),
            ]);
    }

    private function amoUrl(Visit $visit, string $entity, mixed $id): ?string
    {
        if (! $id || ! $visit->account) {
            return null;
        }

        $subdomain = trim((string) $visit->account->subdomain);
        $zone = trim((string) ($visit->account->zone ?: 'ru'));
        $domain = str_contains($zone, '.') ? $zone : 'amocrm.'.$zone;

        return $subdomain !== '' ? sprintf(
            'https://%s.%s/%s/detail/%d',
            $subdomain,
            $domain,
            $entity,
            (int) $id,
        ) : null;
    }
}
