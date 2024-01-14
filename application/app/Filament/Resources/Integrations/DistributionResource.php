<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\Resources\Integrations\DistributionResource\Pages;
use App\Helpers\Traits\SettingResource;
use App\Helpers\Traits\TenantResource;
use App\Models\amoCRM\Staff;
use App\Models\Integrations\Distribution;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class DistributionResource extends Resource
{
    use TenantResource, SettingResource;

    protected static ?string $model = Distribution\Setting::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $recordTitleAttribute = 'Распределение';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Настройки')
                    ->description('Для работы интеграции заполните обязательные поля и выполните настройки')
                    ->schema([
                        Repeater::make('settings')
                            ->label('Основное')
                            ->schema([

                                TextInput::make('link')
                                    ->label('Вебхук ссылка')
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
                                        Distribution\Setting::STRATEGY_SCHEDULE => 'График',
                                        Distribution\Setting::STRATEGY_ROTATION => 'По очереди',
                                        Distribution\Setting::STRATEGY_RANDOM   => 'Равномерно вразброс',
                                        null => '',
                                    ])
                                    ->required(),

                                Radio::make('schedule')
                                    ->label('Учитывать график')
                                    ->options([
                                        'schedule_yes' => 'Да',
                                        'schedule_no'  => 'Нет',
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
                                    )
                            ])
                            ->columns()
                            ->collapsible()
                            ->defaultItems(1)
                            ->reorderable(false)
                            ->reorderableWithDragAndDrop(false)
                            ->addActionLabel('+ Добавить очередь')
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
}
