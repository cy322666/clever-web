<?php

namespace App\Filament\Resources\Core\UserResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;

class StaffsRelationManager extends RelationManager
{
    protected static string $relationship = 'amocrm_staffs';

    protected static ?string $title = 'Сотрудники';

    protected static ?string $recordTitleAttribute = 'name';

    protected function getTableQuery(): Builder|Relation|null
    {
        return Auth::user()->amocrm_staffs()->getQuery();
    }

    public function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('staff_id')
                    ->label('ID'),
                Tables\Columns\TextColumn::make('name')
                    ->label('Имя'),
                Tables\Columns\TextColumn::make('group_name')
                    ->label('Группа'),
            ])
            ->filters([])
            ->headerActions([])
            ->actions([])
            ->bulkActions([])
            ->paginated([20, 30, 50])
            ->emptyStateHeading('Не сихронизировано')
            ->emptyStateDescription('Нажмите на кнопку Синхронизировать')
            ->emptyStateIcon('heroicon-o-exclamation-triangle');
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
