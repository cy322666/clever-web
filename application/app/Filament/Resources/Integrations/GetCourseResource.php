<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\Resources\Integrations\GetCourseResource\Pages;
use App\Helpers\Traits\SettingResource;
use App\Helpers\Traits\TenantResource;
use App\Jobs\GetCourse\OrderSend;
use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\Integrations\GetCourse;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\BooleanColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class GetCourseResource extends Resource
{
    use TenantResource, SettingResource;

    protected static ?string $model = GetCourse\Setting::class;

    protected static ?string $slug = 'settings/getcourse';
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'Геткурс';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (!Auth::user()->is_root) {

            $query->where('user_id', Auth::id());
        }

        return $query;
    }

    public static function getTransactions(): int
    {
        return GetCourse\Form::query()->count() + GetCourse\Order::query()->count();
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make('Настройки')
                    ->hiddenLabel()
                    ->schema([

                        Forms\Components\Repeater::make('order_settings')
                            ->label('Заказы')
                            ->schema([

//                                Forms\Components\Repeater::make('order_settings')
//                                    ->label('Заказы')
//                                    ->schema([

                                        Forms\Components\TextInput::make('link_form')
                                            ->label('Вебхук ссылка')
                                            ->copyable()
                                            ->disabled(),

                                        Forms\Components\TextInput::make('name_form')
                                            ->label('Название')
                                            ->hint('Для различения настроек форм'),

                                        Forms\Components\Select::make('pipeline_id')
                                            ->label('Воронка')
                                            ->options(Status::getPipelines()->pluck('pipeline_name', 'id'))
                                            ->required(),

                                        Forms\Components\Select::make('status_id_order')
                                            ->label('Этап новых заказов')
                                            ->options(Status::getWithoutUnsorted()->pluck('name', 'id'))
                                            ->searchable(),

                                        Forms\Components\Select::make('response_user_id_order')
                                            ->label('Отв. по заказам')
                                            ->options(Staff::getWithUser()->pluck('name', 'id'))
                                            ->searchable(),

                                        Forms\Components\Select::make('status_id_order_close')
                                            ->label('Этап оплаченных заказов')
                                            ->options(Status::getWithoutUnsorted()->pluck('name', 'id'))
                                            ->searchable(),

                                        Forms\Components\TextInput::make('tag_order')->label('Тег для заказов'),

                                        Forms\Components\Radio::make('utms')
                                            ->label('Действия с метками')
                                            ->options([
                                                'merge'   => 'Дополнять',
                                                'rewrite' => 'Перезаписывать',
                                            ])
                                            ->required(),

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
                                                    ->options(Auth::user()->amocrm_fields()->pluck('name', 'id'))
                                                    ->required(),
                                            ])
                                            ->columns()
                                            ->collapsible()
                                            ->defaultItems(1)
                                            ->reorderable(false)
                                            ->reorderableWithDragAndDrop(false)
                                            ->addActionLabel('+ Добавить поле')
//                                    ])
//                                    ->columns()
//                                    ->collapsible()
//                                    ->defaultItems(1)
//                                    ->reorderable(false)
//                                    ->reorderableWithDragAndDrop(false)
//                                    ->addActionLabel('+ Добавить форму'),
                             ])
                            ->columns()
                            ->collapsible()
                            ->defaultItems(1)
                            ->reorderable(false)
                            ->reorderableWithDragAndDrop(false)
                            ->addActionLabel('+ Добавить настройку'),

                        Forms\Components\Repeater::make('settings')
                            ->label('Регистрации')
                            ->schema([

                                Forms\Components\TextInput::make('link_form')
                                    ->label('Вебхук ссылка')
                                    ->copyable()
                                    ->disabled(),

                                Forms\Components\TextInput::make('name_form')
                                    ->label('Название')
                                    ->hint('Для различения настроек форм'),

                                Forms\Components\Select::make('status_id')
                                    ->label('Этап')
                                    ->options(Status::getWithoutUnsorted()->pluck('name', 'id'))
                                    ->required(),

                                Forms\Components\Select::make('pipeline_id')
                                    ->label('Воронка')
                                    ->options(Status::getPipelines()->pluck('pipeline_name', 'id'))
                                    ->required(),

                                Forms\Components\Select::make('responsible_user_id')
                                    ->label('Ответственный')
                                    ->options(Staff::getWithUser()->pluck('name', 'id'))
                                    ->required(),

                                Forms\Components\TextInput::make('tag')
                                    ->label('Тег'),

                                Forms\Components\Radio::make('is_union')
                                    ->label('Объединять повторные сделки')
                                    ->options([
                                        'yes' => 'Да',
                                        'no'  => 'Нет',
                                    ])
                                    ->required(),

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
                                            ->options(Auth::user()->amocrm_fields()->pluck('name', 'id'))
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

                        Forms\Components\Placeholder::make('updated_at')
                            ->label('Обновлен')
                            ->content(fn (?GetCourse\Setting $record): string => $record ? $record->updated_at->diffForHumans() : '-'),
                    ])
                    ->compact()
                    ->columnSpan(1),

            ])->columns(3);
    }

    public static function table(Table $table): Table
    {
//
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
            'edit'   => Pages\EditGetCourse::route('/{record}/edit'),
//            'list'   => ListOrders::route('/orders'),
        ];
    }
}
