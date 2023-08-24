<?php

namespace App\Filament\Resources\Core\UserResource\RelationManagers;

use App\Models\amoCRM\Status;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class StatusesRelationManager extends RelationManager
{
    protected static string $relationship = 'amocrm_statuses';

    protected static ?string $title = 'Этапы и воронки';

    protected static ?string $recordTitleAttribute = 'name';

    protected function getTableQuery(?bool $archive = false): Builder|Relation|null
    {
        return Auth::user()
            ->amocrm_statuses()
            ->where('name', '!=', 'Неразобранное')
            ->where('is_archive', $archive)
            ->getQuery();
    }

    public function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('pipeline_id')
                    ->label('ID воронки'),
                Tables\Columns\TextColumn::make('pipeline_name')
                    ->label('Название воронки'),
                Tables\Columns\TextColumn::make('status_id')
                    ->label('ID этапа'),
                Tables\Columns\TextColumn::make('name')
                    ->label('Название этапа'),
                Tables\Columns\ColorColumn::make('color')
                    ->label('Цвет этапа'),
            ])
            ->filters([])
            ->headerActions([])
            ->actions([])
            ->bulkActions([])
            ->paginated([20, 30, 50])
            ->emptyStateHeading('Не сихронизировано')
            ->emptyStateDescription('Нажмите на кнопку Синхронизировать с amoCRM')
            ->emptyStateIcon('heroicon-o-exclamation-triangle');
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
