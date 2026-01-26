<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\Resources\Integrations\CallTranscriptionResource\Pages;
use App\Helpers\Traits\SettingResource;
use App\Helpers\Traits\TenantResource;
use App\Models\amoCRM\Field;
use App\Models\Integrations\CallTranscription;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;

class CallTranscriptionResource extends Resource
{
    use TenantResource, SettingResource;

    protected static ?string $model = CallTranscription\Setting::class;

    protected static ?string $slug = 'integrations/call-transcription';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'Транскрибация звонков';

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
                                    ->state(fn() => CallTranscription\Setting::$instruction),
                            ]),

                        Forms\Components\Repeater::make('settings')
                            ->hiddenLabel()
                            ->schema([
                                TextInput::make('link')
                                    ->label('Вебхук ссылка')
                                    ->copyable()
                                    ->disabled(),

                                TextInput::make('name')
                                    ->label('Название настройки')
                                    ->required(),

                                TextInput::make('code')
                                    ->label('Ключ настройки')
                                    ->helperText('Используется для маршрутизации вебхуков в amoCRM')
                                    ->required(),

                                Forms\Components\Select::make('ai_provider')
                                    ->label('ИИ провайдер')
                                    ->options([
                                        'yandex' => 'Yandex GPT',
                                        'deepseek' => 'DeepSeek (скоро)',
                                    ])
                                    ->default('yandex')
                                    ->helperText('Пока доступен только Yandex GPT'),

                                Textarea::make('prompt')
                                    ->label('Промпт')
                                    ->rows(6)
                                    ->required(),

                                Forms\Components\Radio::make('result_destination')
                                    ->label('Куда сохранять результат')
                                    ->options([
                                        'field' => 'Записать в поле',
                                        'note' => 'Создать примечание',
                                    ])
                                    ->default('field')
                                    ->required(),

                                Forms\Components\Select::make('entity_type')
                                    ->label('Сущность')
                                    ->options([
                                        'leads' => 'Сделка',
                                        'contacts' => 'Контакт',
                                    ])
                                    ->default('leads')
                                    ->required(),

                                Forms\Components\Select::make('field_id')
                                    ->label('Поле amoCRM')
                                    ->options(fn(Get $get) => $get('entity_type') === 'contacts'
                                        ? Field::getContactSelectFields()
                                        : Field::getLeadSelectFields())
                                    ->searchable()
                                    ->required(fn(Get $get) => $get('result_destination') === 'field')
                                    ->visible(fn(Get $get) => $get('result_destination') === 'field'),

                                TextInput::make('note_prefix')
                                    ->label('Префикс для примечания')
                                    ->helperText('Если указан, добавляется перед результатом')
                                    ->visible(fn(Get $get) => $get('result_destination') === 'note'),

                                TextInput::make('salesbot_id')
                                    ->label('ID Salesbot')
                                    ->helperText('Оставьте пустым, если запускать Salesbot не нужно'),

                                Toggle::make('enabled')
                                    ->label('Активировать настройку')
                                    ->default(true),
                            ])
                            ->columns()
                            ->collapsible()
                            ->defaultItems(1)
                            ->reorderable(false)
                            ->reorderableWithDragAndDrop(false)
                            ->addActionLabel('+ Добавить настройку'),
                    ])
                    ->columnSpan(2),
            ])
            ->columns(3);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'edit' => Pages\EditCallTranscription::route('/{record}/edit'),
        ];
    }

    public static function clearTransactions(int $days = 7): bool
    {
        return true;
    }
}
