<?php

namespace App\Filament\Resources\Integrations\Active;

use App\Filament\Resources\Integrations\Active\LeadResource\Pages;
use App\Models\Integrations\ActiveLead\Lead;
use App\Models\Integrations\ActiveLead\Setting;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class LeadResource extends Resource
{
    protected static ?string $model = Setting::class;

//    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

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
                    ->hidden(fn() => !Auth::user()->is_root),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('lead_id')
                    ->label('ID сделки'),

                Tables\Columns\TextColumn::make('contact_id')
                    ->label('ID контакта'),

                Tables\Columns\TextColumn::make('count_leads')
                    ->label('Всего открытых'),

                Tables\Columns\BooleanColumn::make('is_active')
                    ->label('Есть активная'),

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
            'index' => Pages\ListLeads::route('/'),
        ];
    }
}
