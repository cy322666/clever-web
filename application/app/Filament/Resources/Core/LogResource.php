<?php

namespace App\Filament\Resources\Core;

use App\Filament\Resources\Core\LogResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class LogResource extends Resource
{
    protected static ?string $model = \App\Models\Log::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (!Auth::user()->is_root) {

            $query->where('user_id', Auth::id());
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.email')
                    ->label('Клиент'),

                Tables\Columns\TextColumn::make('url')
                    ->label('URL')
                    ->toggleable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('code')
                    ->label('Код')
                    ->sortable(),

                Tables\Columns\TextColumn::make('method')
                    ->label('Метод')
                    ->sortable(),

                Tables\Columns\TextColumn::make('start')
                    ->label('Запрос')
                    ->date('m-d H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('end')
                    ->label('Ответ')
                    ->date('m-d H:i:s')
                    ->toggledHiddenByDefault()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('execution_time')
                    ->label('Время')
                    ->sortable(),

                Tables\Columns\TextColumn::make('error')
                    ->label('Ошибка')
                    ->sortable()
                    ->toggleable()
                    ->toggledHiddenByDefault()
                    ->wrap(),

                Tables\Columns\TextColumn::make('retries')
                    ->label('Попыток')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('memory_usage')
                    ->label('Память')
                    ->toggleable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('args')
                    ->label('Аргументы')
                    ->toggleable()
                    ->toggledHiddenByDefault()
                    ->wrap(),

                Tables\Columns\TextColumn::make('data')
                    ->label('Тело ответа')
                    ->toggledHiddenByDefault()
                    ->wrap(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('users')
                    ->label('Клиент')
                    ->multiple()
                    ->relationship('user', 'email'),
//                    ->options(fn() => User::query()->get()->pluck('email', 'id'))
                 Tables\Filters\SelectFilter::make('method')
                     ->label('Метод')
                     ->options([
                         'GET' => 'GET',
                         'POST' => 'POST'
                     ]),
                Tables\Filters\Filter::make('error')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->where('error', '!=', null))
            ])
            ->actions([])
            ->bulkActions([])
            ->paginated([30, 50, 100])
            ->emptyStateActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLogs::route('/'),
        ];
    }
}
