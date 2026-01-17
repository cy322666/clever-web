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
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class BizonResource extends Resource
{
    use TenantResource, SettingResource;

    protected static ?string $model = Setting::class;

    protected static ?string $slug = 'integrations/bizon';

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
                Section::make('')
                    ->hiddenLabel()
                    ->schema([
                        Section::make()
                            ->label('Инструкция')
                            ->schema([

                                TextEntry::make('instruction_form')
                                    ->label('Настройка регистраций')
                                    ->bulleted()
                                    ->size(TextSize::Small)
                                    ->state(fn() => Setting::$instructionForm),

                                TextEntry::make('instruction_order')
                                    ->label('Настройка вебинаров')
                                    ->bulleted()
                                    ->size(TextSize::Small)
                                    ->state(fn() => Setting::$instructionWeb),
                            ]),

                        Section::make('')
                            ->hiddenLabel()
                            ->schema([
//                                Fieldset::make('Доступы')
//                                    ->schema([
//                                Forms\Components\TextInput::make('login'),
//                                Forms\Components\TextInput::make('password'),

                                        Forms\Components\TextInput::make('token')
                                            ->label('Токен')
                                            ->required(),

                                        Forms\Components\TextInput::make('link_webinar')
                                            ->label('Вебинарный вебхук')
                                            ->url()
                                            ->copyable()
                                            ->readOnly()
                                            ->helperText('Скопируйте и вставьте в поле после создания отчета в вебинарной комнате'),

                                        Forms\Components\TextInput::make('link_form')
                                            ->label('Регистрационный вебхук')
                                            ->url()
                                            ->readOnly()
                                            ->copyable()
                                            ->helperText('Скопируйте и вставьте в поле вебхука у страницы регистрации')

//                                    ]),
                            ])->columnSpan(2),

                        Section::make('Регистрации')
                            ->schema([

//                                Fieldset::make('Настройка этапов')
//                                    ->schema([
                                        Forms\Components\Select::make('status_id_form')
                                            ->label('Этап')
                                            ->options(Status::getTriggerStatuses())
                                            ->searchable(),

//                                Forms\Components\Select::make('pipeline_id_form')
//                                    ->label('Воронка')
//                                    ->options(Status::getPipelines()->pluck('pipeline_name', 'id'))
//                                    ->searchable(),

                                        Forms\Components\Select::make('responsible_user_id_form')
                                            ->label('Ответственный')
                                            ->options(Staff::getWithUser()->pluck('name', 'staff_id'))
                                            ->searchable(),

                                        Forms\Components\TextInput::make('tag_form')
                                            ->label('Тег'),
//                                    ]),

                            ])
                            ->columnSpan(2),

                        Section::make('Вебинары')
                            ->schema([

                                Fieldset::make('')
                                    ->hiddenLabel()
                                    ->schema([
                                        Forms\Components\Select::make('status_id_cold')
                                            ->label('Этап холодных')
                                            ->options(Status::getTriggerStatuses())
                                            ->searchable(),

                                        Forms\Components\Select::make('status_id_soft')
                                            ->label('Этап теплых')
                                            ->options(Status::getTriggerStatuses())
                                            ->searchable(),

                                        Forms\Components\Select::make('status_id_hot')
                                            ->label('Этап горячих')
                                            ->options(Status::getTriggerStatuses())
                                            ->searchable(),

                                        Forms\Components\TextInput::make('time_cold')
                                            ->numeric()
                                            ->label('Время холодных'),
                                        Forms\Components\TextInput::make('time_soft')
                                            ->numeric()
                                            ->label('Время теплых'),
                                        Forms\Components\TextInput::make('time_hot')
                                            ->numeric()
                                            ->label('Время горячих'),
                                    ]),

                                Fieldset::make('')
                                    ->hiddenLabel()
                                    ->schema([
                                        Forms\Components\TextInput::make('tag_cold')->label('Тег холодных'),
                                        Forms\Components\TextInput::make('tag_soft')->label('Тег теплых'),
                                        Forms\Components\TextInput::make('tag_hot')->label('Тег горячих'),
                                        Forms\Components\TextInput::make('tag')->label('Тег по умолчанию'),

                                        Forms\Components\Select::make('response_user_id')
                                            ->label('Ответственный по умолчанию')
                                            ->options(Staff::getWithUser()->pluck('name', 'staff_id'))
                                            ->searchable(),

//                                Forms\Components\Select::make('pipeline_id')
//                                    ->label('Вебинарная воронка')
//                                    ->options(Status::getPipelines()->pluck('pipeline_name', 'id'))
//                                    ->searchable(),

                                        Forms\Components\Radio::make('utms')
                                            ->label('Действия с метками')
                                            ->options([
                                                'merge'   => 'Дополнять',
                                                'rewrite' => 'Перезаписывать',
                                            ])
                                            ->required(),

                                        //TODO поля меток
                                    ])

                            ])
                            ->columnSpan(2),

                    ])
                    ->columnSpan(2),

                Section::make()
                    ->schema([

                        Action::make('instruction')
                            ->label('Видео инструкция')
                            ->url('https://youtu.be/5-0YZJTE6ww?si=kxKeglVIT--DqcFF')
                            ->openUrlInNewTab(),

                        Section::make()
                            ->schema([

                                TextEntry::make('price6')
                                    ->label('Полгода')
                                    ->money('RU', divideBy: 100)
                                    ->size(TextSize::Medium)
                                    ->state(fn($model): string => $model::$cost['6_month']),

                                TextEntry::make('price12')
                                    ->label('Год')
                                    ->money('RU', divideBy: 100)
                                    ->size(TextSize::Medium)
                                    ->state(fn($model): string => $model::$cost['12_month']),

                                TextEntry::make('bonus')
                                    ->hiddenLabel()
                                    ->size(TextSize::Small)
                                    ->state('*Бесплатно при продлении лицензий через интегратора Clever'),

                                TextEntry::make('bonus2')
                                    ->hiddenLabel()
                                    ->size(TextSize::Small)
                                    ->state('Чтобы узнать больше напишите в чат ниже'),
                            ])
                    ])
                    ->compact()
                    ->columnSpan(1),

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
