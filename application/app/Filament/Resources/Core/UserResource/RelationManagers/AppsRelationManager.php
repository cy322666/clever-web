<?php

namespace App\Filament\Resources\Core\UserResource\RelationManagers;

use Exception;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class AppsRelationManager extends RelationManager
{
    protected static string $relationship = 'apps';

    protected static ?string $title = 'Интеграции';

    protected static ?string $recordTitleAttribute = 'name';

    protected function getTableQuery(): Builder|Relation|null
    {
        return Auth::user()->apps()->getQuery();
    }

    /**
     * @throws Exception
     */
    public function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Название')
                    ->description(function ($record) {

                        return $record->resource_name::getRecordTitle($record);
                    })
            ])
            ->filters([])
            ->headerActions([])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('Настроить')
                    ->url(function (Model $record) {

                        return $record->resource_name::getUrl('edit', ['record' => $record->setting_id]);
                    }),
            ])
            ->bulkActions([])
            ->paginated(false);
    }
}
