<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\Resources\Integrations\TildaResource\Pages;
use App\Helpers\Traits\TenantResource;
use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\Integrations\Tilda;
use App\Models\Log;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class TildaResource extends Resource
{
    use TenantResource;

    protected static ?string $model = Tilda\Setting::class;

    protected static ?string $slug = 'settings/tilda';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'Тильда';

    public static function getRecordTitle(?Model $record = null): string|Htmlable|null
    {
        return 'Тильда';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Настройки')
                    ->description('Для работы интеграции заполните обязательные поля и выполните настройки')
                    ->schema([
                        Forms\Components\Repeater::make('settings')
                            ->label('Основное')
                            ->schema([

                                Forms\Components\TextInput::make('link')
                                    ->label('Вебхук ссылка')
                                    ->disabled(),

                                Forms\Components\Textarea::make('body')
                                    ->label('Тело заявки')
                                    ->disabled(),

                                Forms\Components\Select::make('pipeline_id')
                                    ->label('Воронка')
                                    ->options(Status::getPipelines()->pluck('pipeline_name', 'id'))
                                    ->required(),

                                Forms\Components\Select::make('responsible_user_id')
                                    ->label('Ответственный')
                                    ->options(Staff::getWithUser()->pluck('name', 'id'))
                                    ->required(),

                                Forms\Components\TextInput::make('phone')
                                    ->label('Телефон'),

                                Forms\Components\TextInput::make('email')
                                    ->label('Почта'),

                                Forms\Components\TextInput::make('name')
                                    ->label('Имя'),

                                Forms\Components\TextInput::make('tag')
                                    ->label('Тег'),

                                Forms\Components\Radio::make('is_union')
                                    ->label('Объединять повторные сделки')
                                    ->options([
                                        'yes' => 'Да',
                                        'no'  => 'Нет',
                                    ])
                                    ->required(),

                                Forms\Components\Repeater::make('fields')
                                    ->label('Соотношение полей сделки')
                                    ->schema([

                                        Forms\Components\TextInput::make('field_form')
                                            ->label('Поле из формы')
                                            ->required(),

                                        Forms\Components\Select::make('field_amo')
                                            ->label('Поле из amoCRM')
                                            ->options(Auth::user()->fields()->pluck('name', 'id'))
                                            ->required(),
                                    ])
                                    ->columns()
                                    ->collapsible()
                                    ->defaultItems(1)
                                    ->reorderable(false)
                                    ->reorderableWithDragAndDrop(false)
                                    ->addActionLabel('+ Добавить поле')
                            ])
                            ->columns()
                            ->collapsible()
                            ->defaultItems(1)
                            ->reorderable(false)
                            ->reorderableWithDragAndDrop(false)
                            ->addActionLabel('+ Добавить форму')
                    ])
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
        return [];
    }

    public static function getPages(): array
    {
        return [
            'edit' => Pages\EditTilda::route('/{record}/edit'),
        ];
    }
}
