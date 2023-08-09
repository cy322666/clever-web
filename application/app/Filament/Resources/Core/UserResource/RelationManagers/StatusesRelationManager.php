<?php

namespace App\Filament\Resources\Core\UserResource\RelationManagers;

use App\Models\amoCRM\Status;
use Filament\Forms;
use Filament\Resources\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Table;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class StatusesRelationManager extends RelationManager
{
    protected static string $relationship = 'amocrm_statuses';

    protected static ?string $recordTitleAttribute = 'name';

    protected function getTableQuery(): Builder|Relation|null
    {
        return Status::query()->where('name', '!=', 'Неразобранное');
    }

    public function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([]);
    }

    public function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('pipeline_id'),
                Tables\Columns\TextColumn::make('pipeline_name'),
                Tables\Columns\TextColumn::make('status_id'),
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\ColorColumn::make('color'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
            ])
            ->actions([
            ])
            ->bulkActions([
            ]);
    }
}
