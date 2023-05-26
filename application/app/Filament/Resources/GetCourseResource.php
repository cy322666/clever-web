<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GetCourseResource\Pages;
use App\Models\Integrations\GetCourse;
use Filament\Forms;
use Filament\Resources\Form;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class GetCourseResource extends Resource
{
    protected static ?string $model = GetCourse\Setting::class;

    protected static ?string $navigationIcon = 'heroicon-o-collection';

    protected static ?string $slug = 'integrations/getcourse/settings';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Heading')
                    ->description('Description')
                    ->schema([

                        Forms\Components\Fieldset::make('Доступы')
                            ->schema([
                                Forms\Components\TextInput::make('response_user_id_default')->integer(),
                                Forms\Components\TextInput::make('response_user_id_form')->integer(),
                                Forms\Components\TextInput::make('response_user_id_order')->integer(),
                            ]),

                        Forms\Components\Fieldset::make('Доступы')
                            ->schema([
                                Forms\Components\TextInput::make('status_id_order')->integer(),
                                Forms\Components\TextInput::make('status_id_order_close')->integer(),
                                Forms\Components\TextInput::make('status_id_form')->integer(),
                            ]),

                        Forms\Components\Fieldset::make('Доступы')
                            ->schema([
                                Forms\Components\TextInput::make('lead_name_order')->string(),
                                Forms\Components\TextInput::make('lead_name_form')->string(),
                                Forms\Components\TextInput::make('tag_order')->string(),
                                Forms\Components\TextInput::make('tag_form')->string(),
                            ]),
                    ]),
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
            'index'  => Pages\ListGetCourses::route('/'),
            'create' => Pages\CreateGetCourse::route('/create'),
            'edit'   => Pages\EditGetCourse::route('/{record}/edit'),
        ];
    }
}
