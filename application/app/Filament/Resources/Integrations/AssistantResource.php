<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\Resources\Integrations\AssistantResource\Pages;
use App\Helpers\Traits\SettingResource;
use App\Helpers\Traits\TenantResource;
use App\Models\Integrations\Assistant\Setting;
use Filament\Forms;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;

class AssistantResource extends Resource
{
    use TenantResource, SettingResource;

    protected static ?string $model = Setting::class;

    protected static ?string $slug = 'integrations/assistant';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'AI Ассистент';

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

                        Section::make('Доступ')
                            ->schema([
                                TextInput::make('service_token')
                                    ->label('Service token')
                                    ->disabled()
                                    ->copyable(),

                                TextInput::make('api_base_url')
                                    ->label('Base API URL')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->copyable(),
                            ])
                            ->columns(2),

                        Section::make('Сценарии')
                            ->schema([
                                Toggle::make('settings.chat_enabled')
                                    ->label('Включить чат')
                                    ->default(true),

                                Toggle::make('settings.daily_summary_enabled')
                                    ->label('Включить daily summary')
                                    ->default(true),

                                Toggle::make('settings.weekly_summary_enabled')
                                    ->label('Включить weekly summary')
                                    ->default(true),

                                Toggle::make('settings.telegram_enabled')
                                    ->label('Включить Telegram delivery')
                                    ->default(false),

                                Toggle::make('settings.knowledge_base_enabled')
                                    ->label('Включить knowledge base')
                                    ->default(false),
                            ])
                            ->columns(2),

                        Section::make('n8n и delivery')
                            ->schema([
                                TextInput::make('settings.n8n_base_url')
                                    ->label('n8n URL')
                                    ->url()
                                    ->maxLength(255),

                                TextInput::make('settings.telegram_chat_id')
                                    ->label('Telegram chat id')
                                    ->maxLength(255),

                                CheckboxList::make('settings.enabled_tools')
                                    ->label('Доступные tool endpoint-ы')
                                    ->options([
                                        'department_summary' => 'Department summary',
                                        'manager_summary' => 'Manager summary',
                                        'risky_deals' => 'Risky deals',
                                        'deal_context' => 'Deal context',
                                        'unprocessed_leads' => 'Unprocessed leads',
                                        'overdue_tasks' => 'Overdue tasks',
                                        'deals_without_next_task' => 'Deals without next task',
                                        'conversion_delta' => 'Conversion delta',
                                        'daily_summary' => 'Daily summary',
                                        'weekly_summary' => 'Weekly summary',
                                    ])
                                    ->columns(2),
                            ]),

                        Section::make('Пороговые значения')
                            ->schema([
                                Forms\Components\TextInput::make('settings.risk_stale_days')
                                    ->label('Сколько дней без обновления считать риском')
                                    ->numeric()
                                    ->default(3),

                                Forms\Components\TextInput::make('settings.unprocessed_hours')
                                    ->label('Через сколько часов считать лид необработанным')
                                    ->numeric()
                                    ->default(24),

                                Forms\Components\TextInput::make('settings.high_value_amount')
                                    ->label('Порог суммы для high value deal')
                                    ->numeric()
                                    ->default(100000),
                            ])
                            ->columns(3),
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
            'edit' => Pages\EditAssistant::route('/{record}/edit'),
        ];
    }
}
