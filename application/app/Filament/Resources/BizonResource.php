<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BizonResource\Pages;
use App\Models\Integrations\Bizon\Setting;
use Filament\Forms;
use Filament\Resources\Form;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BizonResource extends Resource
{
    protected static ?string $model = Setting::class;

    protected static ?string $navigationIcon = 'heroicon-o-collection';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Builder\Block::make('heading')
                    ->schema([
                        Forms\Components\TextInput::make('login'),
                        Forms\Components\TextInput::make('password'),
                        Forms\Components\TextInput::make('token'),
                    ]),
                Forms\Components\Builder\Block::make('heading')
                    ->schema([
                        Forms\Components\TextInput::make('status_id_cold')->integer(),
                        Forms\Components\TextInput::make('status_id_soft')->integer(),
                        Forms\Components\TextInput::make('status_id_hot')->integer(),
                    ]),
//                Forms\Components\TextInput::make('pipeline_id')->integer(),
                Forms\Components\Builder\Block::make('heading')
                    ->schema([
                        Forms\Components\TextInput::make('tag_cold'),
                        Forms\Components\TextInput::make('tag_soft'),
                        Forms\Components\TextInput::make('tag_hot'),
                        Forms\Components\TextInput::make('tag'),
                        ]),

                Forms\Components\Builder\Block::make('heading')
                    ->schema([
                        Forms\Components\TextInput::make('time_cold')->integer(),
                        Forms\Components\TextInput::make('time_soft')->integer(),
                        Forms\Components\TextInput::make('time_hot')->integer(),
                        ]),
                Forms\Components\Builder\Block::make('heading')
                    ->schema([
                        Forms\Components\TextInput::make('response_user_id')->integer(),
                        ]),
//                Forms\Components\TextInput::make('response_user_name'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                //
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBizons::route('/'),
            'create' => Pages\CreateBizon::route('/create'),
            'edit' => Pages\EditBizon::route('/{record}/edit'),
        ];
    }
}
