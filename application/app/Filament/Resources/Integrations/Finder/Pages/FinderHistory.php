<?php

namespace App\Filament\Resources\Integrations\Finder\Pages;

use App\Filament\Resources\Integrations\Finder\FinderResource;
use App\Models\Integrations\Finder\Action;
use App\Models\Integrations\Finder\Setting;
use Filament\Actions\Action as TableAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class FinderHistory extends ListRecords
{
    protected static string $resource = FinderResource::class;

    protected static ?string $title = 'История Finder';

    protected function getTableQuery(): ?Builder
    {
        return Action::query()->whereHas('setting', fn (Builder $query) => $query->where('user_id', Auth::id()))->with('conversation');
    }

    protected function getHeaderActions(): array
    {
        return [TableAction::make('settings')->label('Настройки')->url(fn () => FinderResource::getUrl('edit', [
            'record' => Setting::query()->where('user_id', Auth::id())->value('id'),
        ]))];
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->label('Время')->dateTime('d.m.Y H:i:s')->sortable(),
            TextColumn::make('conversation.talk_id')->label('Диалог'),
            TextColumn::make('event')->label('Событие')->formatStateUsing(fn ($state) => $state === 'replied' ? 'Ответ после просрочки' : 'Нет ответа'),
            TextColumn::make('attempt')->label('Проверка'),
            TextColumn::make('kind')->label('Действие')->formatStateUsing(fn ($state) => $state === 'task' ? 'Задача' : 'Сценарий'),
            TextColumn::make('status')->label('Статус')->badge()->formatStateUsing(fn ($state) => self::statuses()[$state] ?? $state)
                ->color(fn ($state) => match ($state) {
                    'succeeded' => 'success', 'failed' => 'danger', 'processing' => 'warning', default => 'gray'
                }),
            TextColumn::make('result_id')->label('ID задачи / запуска'),
            TextColumn::make('error')->label('Ошибка')->wrap(),
        ])->recordUrl(null)->defaultSort('id', 'desc')->poll('30s')->filters([
            SelectFilter::make('status')->label('Статус')->options(self::statuses()),
        ]);
    }

    private static function statuses(): array
    {
        return ['pending' => 'Ожидает', 'processing' => 'Выполняется', 'succeeded' => 'Выполнено / запущено', 'failed' => 'Ошибка', 'cancelled' => 'Отменено'];
    }
}
