<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\Resources\Integrations\BizonResource\Pages;
use App\Helpers\Traits\SettingResource;
use App\Helpers\Traits\TenantResource;
use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\Integrations\Alfa\Transaction;
use App\Models\Integrations\Bizon\Setting;
use App\Models\Integrations\Bizon\Viewer;
use App\Models\Integrations\Bizon\Webinar;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class BizonResource extends Resource
{
    use TenantResource, SettingResource;

    protected static ?string $model = Setting::class;

    protected static ?string $slug = 'settings/bizon';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'Бизон 365';

    public static function getTransactions(): int
    {
        return Viewer::query()->count();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([

                Section::make('Основное')
                    ->hiddenLabel()
                    ->schema([
                        Fieldset::make('Доступы')
                            ->schema([
//                                Forms\Components\TextInput::make('login'),
//                                Forms\Components\TextInput::make('password'),

                                Forms\Components\TextInput::make('token')
                                    ->label('Токен')
                                    ->required(),

                                Forms\Components\TextInput::make('link_webinar')
                                    ->label('Вебинарная ссылка')
                                    ->url()
                                    ->copyable()
                                    ->readOnly()
                                    ->helperText('Скопируйте ее полностью и вставьте в поле после создания отчета в вебинарной комнате'),

                                Forms\Components\TextInput::make('link_form')
                                    ->label('Регистрационная ссылка')
                                    ->url()
                                    ->readOnly()
                                    ->copyable()
                                    ->helperText('Скопируйте ее полностью в вставьте в поле вебхука у страницы регистрации')

                            ]),
                    ])->columnSpan(2),

                Section::make()
                    ->schema([
                        TextEntry::make('link')
                            ->label('Инструкция')
                            ->color('primary')
                            //                            ->markdown(),
                            ->fontFamily(FontFamily::Mono)
                            ->weight(FontWeight::ExtraBold),

                        TextEntry::make('price6')
                            ->money('EUR', divideBy: 100),

                        TextEntry::make('price12')
                            ->money('EUR', divideBy: 100),

                        TextEntry::make('updated_at')
                            ->label('Обновлен')
                    ])
                    ->compact()
                    ->columnSpan(1),

                Section::make('Регистрации')
                    ->description('Настройки для регистраций')
                    ->schema([

                        Fieldset::make('Условия')
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

                    ])
                    ->columnSpan(2),

                Section::make('Вебинар')
                    ->description('Разделите посетителей вебинара на сегементы по времени нахождения на вебинаре')
                    ->schema([

                        Fieldset::make('Условия')
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

                        Fieldset::make('Сделки')
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

                                Forms\Components\Radio::make('utms')
                                    ->label('Действия с метками')
                                    ->options([
                                        'merge'   => 'Дополнять',
                                        'rewrite' => 'Перезаписывать',
                                    ])
                                    ->required(),
                            ])

                    ])
                    ->columnSpan(2),

            ])->columns(3);
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
            'edit'  => Pages\EditBizon::route('/{record}/edit'),
        ];
    }

    public static function clearTransactions(int $days = 7): bool
    {
        Webinar::query()
            ->where('created_at', '<', Carbon::now()
                ->subDays($days)
            )->delete();

        Viewer::query()
            ->where('created_at', '<', Carbon::now()
                ->subDays($days)
                ->format('Y-m-d')
            )->delete();

        return true;
    }
}
