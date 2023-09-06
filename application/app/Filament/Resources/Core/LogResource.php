<?php

namespace App\Filament\Resources\Core;

use App\Filament\Resources\Core\LogResource\Pages;
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

                Tables\Columns\TextColumn::make('code')
                    ->label('Код')
                    ->sortable(),

                Tables\Columns\TextColumn::make('start')
                    ->label('Запрос')
                    ->date('m-d H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('end')
                    ->label('Ответ')
                    ->date('m-d H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('error')
                    ->label('Ошибка')
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('details')
                    ->label('Тело запроса')
                    ->wrap(),

                Tables\Columns\TextColumn::make('data')
                    ->label('Тело ответа')
                    ->toggledHiddenByDefault(true)
                    ->wrap(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([])
            ->actions([])
            ->bulkActions([])
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
