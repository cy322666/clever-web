<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\Resources\Integrations\TildaResource\Pages;
use App\Helpers\Traits\SettingResource;
use App\Helpers\Traits\TenantResource;
use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\Integrations\Alfa\Transaction;
use App\Models\Integrations\Tilda;
use App\Models\Log;
use App\Models\User;
use Carbon\Carbon;
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

    protected static ?string $slug = 'settings/tilda';
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

                                Forms\Components\Select::make('pipeline_id')
                                    ->label('Воронка')
                                    ->options(Status::getPipelines()->pluck('pipeline_name', 'id'))
                                    ->required(),

                                Forms\Components\Select::make('status_id')
                                    ->label('Этап')
                                    ->options(Status::getWithUser()->pluck('name', 'id'))
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

                                Forms\Components\Radio::make('products')
                                    ->label('Работать с товарами')
                                    ->options([
                                        'yes' => 'Да',
                                        'no'  => 'Нет',
                                    ])
                                    ->required(),

                                Forms\Components\Select::make('field_products')
                                    ->label('Поле для товаров')
                                    ->options(Auth::user()->amocrm_fields()->pluck('name', 'id')),

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
                            ->content(fn (?Tilda\Setting $record): string => $record ? $record->updated_at->diffForHumans() : '-'),
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
