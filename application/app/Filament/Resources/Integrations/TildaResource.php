<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\Resources\Integrations\TildaResource\Pages;
use App\Helpers\Traits\SettingResource;
use App\Helpers\Traits\TenantResource;
use App\Models\amoCRM\Field;
use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\Integrations\Alfa\Transaction;
use App\Models\Integrations\Tilda;
use App\Models\Log;
use App\Models\User;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Novadaemon\FilamentPrettyJson\Form\PrettyJsonField;

class TildaResource extends Resource
{
    use TenantResource, SettingResource;

    protected static ?string $model = Tilda\Setting::class;

    protected static ?string $slug = 'integrations/tilda';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'Тильда';

    public static function getTransactions(): int
    {
        return Tilda\Form::query()->count();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([

                Section::make()
                    ->hiddenLabel()
                    ->schema([

                        Section::make()
                            ->label('Инструкция')
                            ->schema([

                                TextEntry::make('instruction')
                                    ->hiddenLabel()
                                    ->bulleted()
                                    ->size(TextSize::Small)
                                    ->state(fn() => Tilda\Setting::$instruction),

                                TextEntry::make('ps')
                                    ->hiddenLabel()
                                    ->size(TextSize::ExtraSmall)
                                    ->state(fn() => 'Если есть сложности то смотри Видео инструкцию (кнопка справа) или напиши в чат ниже'),
                            ]),

                        Forms\Components\Repeater::make('settings')
                            ->hiddenLabel()
                            ->schema([

                                Forms\Components\TextInput::make('link')
                                    ->label('Вебхук ссылка')
                                    ->copyable()
                                    ->disabled(),

                                PrettyJsonField::make('body')
                                    ->label('Тело заявки')
                                    ->disabled(),

                                Forms\Components\TextInput::make('name_form')
                                    ->label('Название')
                                    ->hint('Для различения настроек форм'),

                                Forms\Components\Select::make('status_id')
                                    ->label('Этап')
                                    ->options(Status::getTriggerStatuses())
                                    ->searchable()
                                    ->required(),

                                Forms\Components\Select::make('responsible_user_id')
                                    ->label('Ответственный')
                                    ->options(Staff::getWithUser()->pluck('name', 'staff_id'))
                                    ->searchable()
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

                                Forms\Components\Radio::make('products')
                                    ->label('Работать с товарами')
                                    ->options([
                                        'yes' => 'Да',
                                        'no'  => 'Нет',
                                    ])
                                    ->required(),

                                Forms\Components\Select::make('field_products')
                                    ->label('Поле для товаров')
                                    ->searchable()
                                    ->options(Field::getLeadSelectFields()),

                                Forms\Components\Radio::make('utms')
                                    ->label('Действия с метками')
                                    ->options([
                                        'merge'   => 'Дополнять',
                                        'rewrite' => 'Перезаписывать',
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
                                            ->searchable()
                                            ->options(Field::getLeadSelectFields())
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
                    ])->columnSpan(2),

                Section::make()
                    ->schema([

                        Action::make('instruction')
                            ->label('Видео инструкция')
                            ->url('https://youtu.be/b5aPWhK2oc8?si=nSGpU-XRSlTRScNQ')
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

    public static function clearTransactions(int $days = 7): bool
    {
        Tilda\Form::query()
            ->where('created_at', '<', Carbon::now()
                ->subDays($days)
                ->format('Y-m-d')
            )->delete();

        return true;
    }
}
