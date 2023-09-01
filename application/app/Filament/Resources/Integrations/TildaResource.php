<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\Resources\Integrations\TildaResource\Pages;
use App\Models\Integrations\Tilda;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class TildaResource extends Resource
{
    protected static ?string $model = Tilda\Setting::class;

    protected static ?string $slug = 'settings/tilda';

//    protected static ?string $navigationIcon = 'heroicon-o-collection';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'Тильда';

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        return 'Тильда';
    }

    //поле с массивом
    //поля с соотношениями полей
    //json с настройками
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Repeater::make('members')
                    ->schema([
                        Forms\Components\TextInput::make('name')->required(),
                        Forms\Components\Select::make('role')
                            ->options([
                                'member' => 'Member',
                                'administrator' => 'Administrator',
                                'owner' => 'Owner',
                            ])
                            ->required(),
                    ])
                    ->columns(2)
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
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateActions([
                Tables\Actions\CreateAction::make(),
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
            'index' => Pages\ListTildas::route('/'),
            'create' => Pages\CreateTilda::route('/create'),
            'edit' => Pages\EditTilda::route('/{record}/edit'),
        ];
    }
}
