<?php

namespace App\Filament\Resources\Core;

use App\Filament\Resources\Core\UserResource\Pages;
use App\Filament\Resources\Core\UserResource\RelationManagers;
use App\Models\User;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\Resource;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use STS\FilamentImpersonate\Tables\Actions\Impersonate;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Основная информация')
                    ->schema([
                        Forms\Components\TextInput::make('email')
                            ->label('Почта')
                            ->disabled(),
                        Forms\Components\TextInput::make('name')
                            ->label('Логин')
                            ->disabled(),
                        Forms\Components\TextInput::make('uuid')
                            ->label('Идентификатор')
                            ->disabled(),
                ])->columnSpan([
                    'sm' => 2,
                ]),
                Forms\Components\Card::make()
                    ->schema([
                        Forms\Components\Placeholder::make('created_at')
                            ->label('Создан')
                            ->content(fn (?User $record): string => $record ? $record->created_at->diffForHumans() : '-'),
                        Forms\Components\Placeholder::make('updated_at')
                            ->label('Обновлен')
                            ->content(fn (?User $record): string => $record ? $record->updated_at->diffForHumans() : '-'),
                    ])
                    ->columnSpan(1),
            ])->columns([
                'sm' => 3,
                'lg' => null,
            ]);
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

                Tables\Columns\BooleanColumn::make('active')
                    ->label('Статус')
                    ->sortable(),

                Tables\Columns\BooleanColumn::make('is_root')
                    ->label('Админ')
                    ->sortable(),

                Tables\Columns\BooleanColumn::make('edit')
                    ->label('Ред')
                    ->url(function (User $user) {

                        return Pages\EditUser::getUrl(['record' => $user->id]);
                    }),
            ])
            ->filters([])
            ->paginated([10, 30, 'all'])
            ->actions([
//                Tables\Actions\EditAction::make(),
                Impersonate::make()
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
