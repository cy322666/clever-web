<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\Resources\Integrations\AmoDataResource\Pages;
use App\Helpers\Traits\SettingResource;
use App\Helpers\Traits\TenantResource;
use App\Models\Integrations\AmoData\Setting;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;

class AmoDataResource extends Resource
{
    use TenantResource, SettingResource;

    protected static ?string $model = Setting::class;

    protected static ?string $slug = 'integrations/amo-data';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'amoCRM Data';

    public static function getTransactions(): int
    {
        return 0;
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
                                    ->state(fn() => Setting::$instruction),
                            ]),

                        Section::make('Настройки синхронизации')
                            ->schema([
                                Toggle::make('active')
                                    ->label('Включить sync')
                                    ->default(false),

                                Toggle::make('settings.sync_deals')
                                    ->label('Синхронизировать сделки')
                                    ->default(true),

                                Toggle::make('settings.sync_tasks')
                                    ->label('Синхронизировать задачи')
                                    ->default(true),

                                Toggle::make('settings.store_payloads')
                                    ->label('Хранить raw payload')
                                    ->default(true),

                                TextInput::make('settings.sync_interval_minutes')
                                    ->label('Интервал periodic sync (мин)')
                                    ->numeric()
                                    ->default(30)
                                    ->minValue(15),
                            ])
                            ->columns(2),

                        Section::make('Состояние')
                            ->poll('5s')
                            ->schema([
                                TextInput::make('sync_status')
                                    ->label('Статус')
                                    ->disabled(),

                                TextInput::make('initial_synced_at')
                                    ->label('Initial sync')
                                    ->disabled(),

                                TextInput::make('last_attempt_at')
                                    ->label('Последняя попытка')
                                    ->disabled(),

                                TextInput::make('last_successful_sync_at')
                                    ->label('Последний успешный sync')
                                    ->disabled(),

                                TextInput::make('last_leads_count')
                                    ->label('Последний batch deals')
                                    ->disabled(),

                                TextInput::make('last_tasks_count')
                                    ->label('Последний batch tasks')
                                    ->disabled(),

                                TextInput::make('last_events_count')
                                    ->label('Последний batch events')
                                    ->disabled(),

                                TextInput::make('last_error')
                                    ->label('Последняя ошибка')
                                    ->disabled()
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                    ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'edit' => Pages\EditAmoData::route('/{record}/edit'),
        ];
    }
}
