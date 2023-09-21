<?php

namespace App\Filament\Resources\Integrations\DocResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class AmocrmFieldsRelationManager extends RelationManager
{
    protected static string $relationship = 'amocrm_fields';

    protected static ?string $title = 'Поля';

    protected static ?string $recordTitleAttribute = 'name';

    protected function getTableQuery(): Builder|Relation|null
    {
        return Auth::user()->amocrm_fields()->getQuery();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('field_id'),
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('entity_type'),
            ])
            ->filters([
                //
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([])
            ->emptyStateActions([
                Tables\Actions\CreateAction::make(),
            ]);
    }
}
