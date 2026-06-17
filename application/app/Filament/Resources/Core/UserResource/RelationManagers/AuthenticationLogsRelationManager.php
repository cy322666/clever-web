<?php

namespace App\Filament\Resources\Core\UserResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AuthenticationLogsRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'authentications';

    protected static ?string $recordTitleAttribute = 'id';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'История входов';
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn(Builder $query) => $query->with('authenticatable'))
            ->defaultSort(
                config('filament-authentication-log.sort.column', 'login_at'),
                config('filament-authentication-log.sort.direction', 'desc'),
            )
            ->columns([
                TextColumn::make('ip_address')
                    ->label('IP-адрес')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('user_agent')
                    ->label('Устройство')
                    ->searchable()
                    ->sortable()
                    ->limit(50)
                    ->tooltip(function (TextColumn $column): ?string {
                        $state = $column->getState();

                        if (strlen($state) <= $column->getCharacterLimit()) {
                            return null;
                        }

                        return $state;
                    }),

                TextColumn::make('login_at')
                    ->label('Время входа')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                IconColumn::make('login_successful')
                    ->label('Успешный вход')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('logout_at')
                    ->label('Время выхода')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                IconColumn::make('cleared_by_user')
                    ->label('Сброшено пользователем')
                    ->boolean()
                    ->sortable(),
            ])
            ->emptyStateHeading('История входов пуста')
            ->filters([])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    protected function canCreate(): bool
    {
        return false;
    }

    protected function canEdit(Model $record): bool
    {
        return false;
    }

    protected function canDelete(Model $record): bool
    {
        return false;
    }
}
