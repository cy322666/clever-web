<?php

namespace App\Filament\Resources\Core\UserResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class StatusesRelationManager extends RelationManager
{
    protected static string $relationship = 'amocrm_statuses';

    protected static ?string $title = 'Этапы и воронки';

    protected static ?string $recordTitleAttribute = 'name';

    protected function getTableQuery(): Builder|Relation|null
    {
        return $this->getOwnerRecord()
            ->amocrm_statuses()
            ->where('name', '!=', 'Неразобранное')
            ->where('is_archive', false)
            ->getQuery();
    }

    public function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('pipeline_id')
                    ->label('ID воронки'),
                Tables\Columns\TextColumn::make('pipeline_name')
                    ->label('Название воронки')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status_id')
                    ->label('ID этапа'),
                Tables\Columns\TextColumn::make('name')
                    ->label('Название этапа')
                    ->searchable(),
                Tables\Columns\ColorColumn::make('color')
                    ->label('Цвет этапа'),
            ])
            ->filters([])
            ->headerActions([])
            ->actions([])
            ->bulkActions([])
            ->paginated([20, 30, 50])
            ->emptyStateHeading('Не сихронизировано')
            ->emptyStateDescription('Нажмите на кнопку Синхронизировать')
            ->emptyStateIcon('heroicon-o-exclamation-triangle')
            ->defaultSort('pipeline_name');
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
