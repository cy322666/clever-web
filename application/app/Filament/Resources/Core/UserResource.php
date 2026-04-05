<?php

namespace App\Filament\Resources\Core;

use App\Filament\Resources\Core\UserResource\Pages;
use App\Filament\Resources\Core\UserResource\RelationManagers;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use App\Services\Core\UserDeleteService;
use STS\FilamentImpersonate\Actions\Impersonate;
use Tapp\FilamentAuthenticationLog\RelationManagers\AuthenticationLogsRelationManager;
use Throwable;

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
                    ->searchable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Название')
                    ->searchable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable(
                        ['email'],
                        function (Builder $query, string $search): Builder {
                            $term = strtolower(trim($search));

                            return $query->whereRaw('LOWER(email) LIKE ?', ['%' . $term . '%']);
                        }
                    )
                    ->forceSearchCaseInsensitive(),

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
            ->searchPlaceholder('Поиск по email')
            ->defaultSort('id', 'desc')
            ->paginated(false)
            ->actions([
                Action::make('widgets')
                    ->label('Виджеты')
                    ->icon('heroicon-o-puzzle-piece')
                    ->visible(fn(): bool => auth()->check() && (bool)auth()->user()?->is_root)
                    ->url(fn(User $user): string => Pages\ViewUser::getUrl(['record' => $user->id])),
                Action::make('delete_user')
                    ->label('Удалить')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Удалить пользователя?')
                    ->modalDescription('Будут удалены пользователь, установки, транзакции и связанные данные.')
                    ->visible(
                        fn(User $user): bool => auth()->check()
                            && (bool)auth()->user()?->is_root
                            && !(bool)$user->is_root
                            && (int)auth()->id() !== (int)$user->id
                    )
                    ->action(function (User $user): void {
                        try {
                            $stats = app(UserDeleteService::class)->delete($user, auth()->user());

                            Notification::make()
                                ->title('Пользователь удален')
                                ->body('Удалено записей: ' . array_sum($stats))
                                ->success()
                                ->send();
                        } catch (Throwable $e) {
                            Notification::make()
                                ->title('Удаление не выполнено')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
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
