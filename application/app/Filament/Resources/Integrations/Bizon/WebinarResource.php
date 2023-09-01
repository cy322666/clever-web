<?php

namespace App\Filament\Resources\Integrations\Bizon;

use App\Filament\Resources\Integrations\Bizon\WebinarResource\Pages;
use App\Filament\Resources\Integrations\Bizon\WebinarResource\RelationManagers;
use App\Models\Integrations\Bizon\Webinar;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class WebinarResource extends Resource
{
    protected static ?string $model = Webinar::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_id', Auth::id());
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('roomid')
                    ->label('ID')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('room_title')
                    ->label('Название')
                    ->wrap()
                    ->searchable(),

                Tables\Columns\TextColumn::make('created')
                    ->label('Создан')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('count')
                    ->label('Зрителей')
                    ->state(
                        fn(Webinar $webinar) => $webinar->viewers()->count()
                    ),

                Tables\Columns\TextColumn::make('success')
                    ->label('Отправлено')
                    ->state(
                        fn(Webinar $webinar) => $webinar->viewers()->where('status', 1)->count()
                    ),

                Tables\Columns\TextColumn::make('fails')
                    ->label('Ошибок')
                    ->state(
                        fn(Webinar $webinar) => $webinar->viewers()->where('status', 2)->count()
                    ),

                //TODO relationship methods
                Tables\Columns\BooleanColumn::make('status')
                    ->label('Выгружен')
                    ->state(fn(Webinar $webinar) =>
                        $webinar
                            ->viewers()
                            ->where('status', 1)
                            ->count() ==
                        $webinar
                            ->viewers()
                            ->count()
                    )
//                    ->trueIcon('heroicon-o-badge-check')
//                    ->falseIcon('heroicon-o-x-circle'),
            ])
            ->filters([])
            ->actions([
//                Tables\Actions\Action::make('edit')
//                    ->url(fn (Webinar $record): string => route('posts.edit', $record))
//                    ->openUrlInNewTab()
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([]),
            ])
            ->emptyStateActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWebinars::route('/'),
        ];
    }
}
