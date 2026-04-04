<?php

namespace App\Filament\Resources\Integrations\AmoDataResource\Pages;

use App\Filament\Resources\Integrations\AmoDataResource;
use App\Models\Integrations\AmoData\SyncRun;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListSyncRuns extends ListRecords
{
    protected static string $resource = AmoDataResource::class;

    protected static ?string $title = 'История выгрузок';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('started_at')
                    ->label('Запуск')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('finished_at')
                    ->label('Завершение')
                    ->dateTime()
                    ->placeholder('В процессе')
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Тип')
                    ->state(fn(SyncRun $run) => $run->type === 'initial' ? 'Первая выгрузка' : 'Плановая выгрузка')
                    ->badge(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge(),

                Tables\Columns\TextColumn::make('leads_synced')
                    ->label('Сделки'),

                Tables\Columns\TextColumn::make('tasks_synced')
                    ->label('Задачи'),

                Tables\Columns\TextColumn::make('events_created')
                    ->label('События'),

                Tables\Columns\TextColumn::make('error')
                    ->label('Ошибка')
                    ->limit(60)
                    ->tooltip(fn(SyncRun $run) => $run->error)
                    ->wrap(),
            ])
            ->defaultSort('started_at', 'desc')
            ->paginated([20, 50, 'all'])
            ->poll('5s')
            ->filters([])
            ->actions([])
            ->bulkActions([])
            ->emptyStateActions([]);
    }

    protected function getTableQuery(): ?Builder
    {
        $query = SyncRun::query();

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
