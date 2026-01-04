<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\Resources\Integrations\DistributionResource\Pages;
use App\Helpers\Traits\SettingResource;
use App\Helpers\Traits\TenantResource;
use App\Models\amoCRM\Staff;
use App\Models\Integrations\Bizon\Viewer;
use App\Models\Integrations\Distribution;
use Carbon\Carbon;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class DistributionResource extends Resource
{
    use TenantResource, SettingResource;

    protected static ?string $model = Distribution\Setting::class;

    protected static ?string $recordTitleAttribute = 'Распределение';

    public static function getTransactions(): int
    {
        return Distribution\Transaction::query()->count();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \Filament\Schemas\Components\Section::make('Настройки')
                   ->hiddenLabel()
                    ->schema([
                        Repeater::make('settings')
                            ->label('Основное')
                            ->schema([

                                TextInput::make('link')
                                    ->label('Вебхук ссылка')
                                    ->copyable()
                                    ->disabled(),

                                TextInput::make('name')
                                    ->label('Название')
                                    ->hint('Для различения очередей'),

//                                Forms\Components\Select::make('field_amo')
//                                    ->label('Поле из amoCRM')
//                                    ->options(Auth::user()->amocrm_fields()->pluck('name', 'id'))
//                                    ->required(),

                                Select::make('strategy')
                                    ->label('Тип распределения')
                                    ->options([
                                        Distribution\Setting::STRATEGY_ROTATION => 'По очереди',
                                        Distribution\Setting::STRATEGY_RANDOM   => 'Вразброс',
                                    ])
                                    ->required(),

                                Radio::make('schedule')
                                    ->label('Учитывать график')
                                    ->options([
                                        'schedule_yes' => 'Да',
                                        'schedule_no'  => 'Нет',
                                    ])
                                    ->required(),

                                Radio::make('check_active')
                                    ->label('Учитывать активные сделки')
                                    ->options([
                                        'yes' => 'Да',
                                        'no'  => 'Нет',
                                    ])
                                    ->required(),

                                Radio::make('update_tasks')
                                    ->label('Менять в задачах')
                                    ->options([
                                        'yes' => 'Да',
                                        'no'  => 'Нет',
                                    ])
                                    ->required(),

                                Radio::make('update_contact_company')
                                    ->label('Менять в контакте/компании')
                                    ->options([
                                        'yes' => 'Да',
                                        'no'  => 'Нет',
                                    ])
                                    ->required(),

                                Select::make('staffs')
                                    ->label('Сотрудники')
                                    ->multiple()
                                    ->options(
                                        Staff::query()
                                            ->where('user_id', Auth::id())
                                            ->get()
                                            ->pluck('name', 'staff_id')
                                    )->searchable()
                            ])
                            ->columns()
                            ->collapsible()
                            ->defaultItems(1)
                            ->reorderable(false)
                            ->reorderableWithDragAndDrop(false)
                            ->addActionLabel('+ Добавить очередь')
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

            ])->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                //
            ])
            ->filters([
                //
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
            'edit' => Pages\EditDistribution::route('/{record}/edit'),
        ];
    }

    public static function clearTransactions(int $days = 7): bool
    {
        Distribution\Transaction::query()
            ->where('created_at', '<', Carbon::now()
                ->subDays($days)
                ->format('Y-m-d')
            )->delete();

        return true;
    }
}
