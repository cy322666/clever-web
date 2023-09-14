<?php

namespace App\Filament\Resources\Integrations\Dadata;

use App\Filament\Resources\Integrations\Dadata\InfoResource\Pages;
use App\Models\Integrations\Dadata\Lead;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class InfoResource extends Resource
{
    protected static ?string $model = Lead::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (!Auth::user()->is_root) {

            $query->where('user_id', Auth::id());
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.email')
                    ->label('Клиент')
                    ->searchable()
                    ->sortable()
                    ->hidden(fn() => !Auth::user()->is_root),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('lead_id')
                    ->label('ID сделки')
                    ->searchable(),

                Tables\Columns\TextColumn::make('contact_id')
                    ->label('ID контакта')
                    ->searchable(),

                Tables\Columns\TextColumn::make('phone_at')
                    ->label('Тел изначальный'),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Тел формат'),

                Tables\Columns\TextColumn::make('country')
                    ->label('Страна'),

                Tables\Columns\TextColumn::make('region')
                    ->label('Регион'),

                Tables\Columns\BooleanColumn::make('status')
                    ->label('Выгружен'),
            ])
            ->filters([])
            ->actions([])
            ->bulkActions([])
            ->emptyStateActions([])
            ->paginated([20, 30, 50]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInfos::route('/'),
        ];
    }
}
