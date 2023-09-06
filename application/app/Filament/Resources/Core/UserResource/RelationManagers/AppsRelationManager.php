<?php

namespace App\Filament\Resources\Core\UserResource\RelationManagers;

use App\Models\App;
use Exception;
use Filament\Forms;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Infolist;
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
        return Auth::user()->apps()->where('status', '!=', 0)->getQuery();
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
                    ->state(function ($record) {

                        return $record->resource_name::getRecordTitle($record);
                    }),

                Tables\Columns\TextColumn::make('expires_tariff_at')
                    ->label('Истекает'),

                 Tables\Columns\BadgeColumn::make('status')
                     ->label('Статус')
                     ->color(fn (App $app): string => match ($app->status) {
                         App::STATE_CREATED  => 'gray',
                         App::STATE_INACTIVE => 'warning',
                         App::STATE_ACTIVE   => 'success',
                     })
                     ->formatStateUsing(fn($state) => match($state) {
                         App::STATE_CREATED  => App::STATE_CREATED_WORD,
                         App::STATE_INACTIVE => App::STATE_INACTIVE_WORD,
                         App::STATE_ACTIVE   => App::STATE_ACTIVE_WORD,
                     }),
            ])
            ->filters([])
            ->headerActions([])
            ->actions([
//                Tables\Actions\Action::make('view')
//                    ->label('Настроить')
//                    ->url(function (Model $record) {
//
//                        return $record->resource_name::getUrl('edit', ['record' => $record->setting_id]);
//                    }),
                Tables\Actions\Action::make('view')
                    ->label('Оплатить')
                    ->url(function (Model $record) {

                        return $record->resource_name::getUrl('edit', ['record' => $record->setting_id]);
                    }),
            ])
            ->bulkActions([])
            ->paginated(false);
    }
}
