<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\Resources\Integrations\BizonResource\Pages;
use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\Integrations\Bizon\Setting;
use Filament\Forms;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\Split;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Env;

class BizonResource extends Resource
{
    protected static ?string $model = Setting::class;

    protected static ?string $slug = 'settings/bizon';

//    protected static ?string $navigationIcon = 'heroicon-o-collection';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'Бизон 365';

    public static function getRecordTitle(?Model $record = null): string|Htmlable|null
    {
        return 'Бизон365';
    }

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([

//                Split::make([
//                    Section::make([
//                        TextEntry::make('title')
//                            ->weight(FontWeight::Bold),
//                        TextEntry::make('content')
//                            ->markdown()
//                            ->prose(),
//                    ])->grow(),
//                    Section::make([
//                        TextEntry::make('created_at')
//                            ->dateTime(),
//                        TextEntry::make('published_at')
//                            ->dateTime(),
//                    ]),
//                ])->from('md'),

                Forms\Components\Section::make('Настройки')
                    ->description('Для работы интеграции заполните обязательные поля')
                    ->schema([
                        Forms\Components\Fieldset::make('Доступы')
                            ->schema([
//                                Forms\Components\TextInput::make('login'),
//                                Forms\Components\TextInput::make('password'),
                                Forms\Components\TextInput::make('token')
                                    ->label('Токен')
                                    ->required(),

                                Forms\Components\TextInput::make('link_webinar')
                                    ->label('Вебинарная ссылка')
                                    ->url()
                                    ->readOnly()
                                    ->helperText('Скопируйте ее полностью в настройки вебинарной комнаты'),

                                Forms\Components\TextInput::make('link_form')
                                    ->label('Регистрационная ссылка')
                                    ->url()
                                    ->readOnly()
                                    ->helperText('Скопируйте ее полностью в настройки страницы регистрации')

                            ])->columnSpan(2),
                    ]),

                Forms\Components\Section::make('Регистрации')
                    ->description('Настройки для регистраций')
                    ->schema([

                        Forms\Components\Fieldset::make('Условия')
                            ->schema([
                                Forms\Components\Select::make('status_id_form')
                                    ->label('Этап')
                                    ->options(Status::getWithoutUnsorted()->pluck('name', 'id'))
                                    ->searchable(),

                                Forms\Components\Select::make('pipeline_id_form')
                                    ->label('Воронка')
                                    ->options(Status::getPipelines()->pluck('pipeline_name', 'id'))
                                    ->searchable(),

                                Forms\Components\Select::make('responsible_user_id_form')
                                    ->label('Ответственный')
                                    ->options(Staff::getWithUser()->pluck('name', 'id'))
                                    ->searchable(),

                                Forms\Components\TextInput::make('tag_form')
                                    ->label('Тег'),
                            ]),

                    ])->columns([
                        'sm' => 2,
                        'lg' => null,
                    ]),

                Forms\Components\Section::make('Вебинар')
                    ->description('Разделите посетителей вебинара на сегементы по времени нахождения на вебинаре')
                    ->schema([

                        Forms\Components\Fieldset::make('Условия')
                            ->schema([
                                    Forms\Components\Select::make('status_id_cold')
                                        ->label('Этап холодных')
                                        ->options(Status::getWithoutUnsorted()->pluck('name', 'id'))
                                        ->searchable(),

                                    Forms\Components\Select::make('status_id_soft')
                                        ->label('Этап теплых')
                                        ->options(Status::getWithoutUnsorted()->pluck('name', 'id'))
                                        ->searchable(),

                                    Forms\Components\Select::make('status_id_hot')
                                        ->label('Этап горячих')
                                        ->options(Status::getWithoutUnsorted()->pluck('name', 'id'))
                                        ->searchable(),

                                    Forms\Components\TextInput::make('time_cold')
                                        ->label('Время холодных'),
                                    Forms\Components\TextInput::make('time_soft')
                                        ->label('Время теплых'),
                                    Forms\Components\TextInput::make('time_hot')
                                        ->label('Время горячих'),
                                ]),

                        Forms\Components\Fieldset::make('Сделки')
                            ->schema([
                                Forms\Components\TextInput::make('tag_cold')->label('Тег холодных'),
                                Forms\Components\TextInput::make('tag_soft')->label('Тег теплых'),
                                Forms\Components\TextInput::make('tag_hot')->label('Тег горячих'),
                                Forms\Components\TextInput::make('tag')->label('Тег по умолчанию'),

                                Forms\Components\Select::make('response_user_id')
                                    ->label('Ответственный по умолчанию')
                                    ->options(Staff::getWithUser()->pluck('name', 'id'))
                                    ->searchable(),

                                Forms\Components\Select::make('pipeline_id')
                                    ->label('Вебинарная воронка')
                                    ->options(Status::getPipelines()->pluck('pipeline_name', 'id'))
                                    ->searchable(),
                            ])
                            ->columns([
                                'sm' => 2,
                                'lg' => null,
                            ]),

                    ])->columns([
                        'sm' => 2,
                        'lg' => null,
                    ]),
            ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                //
            ])
            ->filters([
                //
            ])
            ->actions([
//                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
//                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBizons::route('/'),
            'edit'  => Pages\EditBizon::route('/{record}/edit'),
        ];
    }
}
