<?php

namespace App\Filament\Resources\Core;

use App\Filament\Resources\Core\UserResource\Pages;
use App\Filament\Resources\Core\UserResource\RelationManagers;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use STS\FilamentImpersonate\Actions\Impersonate;
use Tapp\FilamentAuthenticationLog\RelationManagers\AuthenticationLogsRelationManager;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    public static function canViewAny(): bool
    {
        return auth()->check();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canView(Model $record): bool
    {
        if (!$record instanceof User) {
            return false;
        }

        return auth()->check()
            && ((bool)auth()->user()?->is_root || (int)auth()->id() === (int)$record->id);
    }

    public static function canEdit(Model $record): bool
    {
        if (!$record instanceof User) {
            return false;
        }

        return auth()->check()
            && ((bool)auth()->user()?->is_root || (int)auth()->id() === (int)$record->id);
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->check() && (bool)auth()->user()?->is_root;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Название')
                    ->searchable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('count_inputs')
                    ->label('Запросов')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\BooleanColumn::make('active')
                    ->label('Статус')
                    ->sortable(),

                Tables\Columns\BooleanColumn::make('is_root')
                    ->label('Админ')
                    ->sortable(),

                //TODO нет иконки
                Tables\Columns\IconColumn::make('edit')
                    ->label('Ред')
                    ->icon('heroicon-m-star')
                    ->url(function (User $user) {

                        return Pages\EditUser::getUrl(['record' => $user->id]);
                    }),
            ])
            ->filters([])
            ->paginated([10, 30, 'all'])
            ->actions([
                Tables\Actions\Action::make('widgets')
                    ->label('Виджеты')
                    ->icon('heroicon-o-puzzle-piece')
                    ->visible(fn(): bool => auth()->check() && (bool)auth()->user()?->is_root)
                    ->url(fn(User $user): string => Pages\ViewUser::getUrl(['record' => $user->id])),
                Impersonate::make()
                    ->hiddenLabel()
                    ->hidden(fn(): bool => !auth()->check() || !(bool)auth()->user()?->is_root)
                    ->openUrlInNewTab()
                    ->redirectTo(route('filament.app.resources.core.users.view', ['record' => Auth::id()]))
            ])
            ->bulkActions([
//                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\AppsRelationManager::class,
            RelationManagers\StaffsRelationManager::class,
            RelationManagers\StatusesRelationManager::class,
            AuthenticationLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListUsers::route('/'),
            'edit'   => Pages\EditUser::route('/{record}/edit'),
            'view'   => Pages\ViewUser::route('/{record}'),
        ];
    }
}
