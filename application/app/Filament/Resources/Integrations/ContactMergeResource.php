<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\Resources\Integrations\ContactMergeResource\Pages\EditContactMerge;
use App\Filament\Resources\Integrations\ContactMergeResource\Pages\ListContactMerge;
use App\Helpers\Traits\SettingResource;
use App\Helpers\Traits\TenantResource;
use App\Models\amoCRM\Field;
use App\Models\Integrations\ContactMerge\Record;
use App\Models\Integrations\ContactMerge\Setting;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ContactMergeResource extends Resource
{
    use TenantResource, SettingResource;

    protected static ?string $model = Setting::class;

    protected static ?string $slug = 'integrations/contact-merge';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'Склейка контактов';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Поиск дублей')
                    ->description('Выберите поля для поиска дублей и правила объединения данных.')
                    ->schema([
                        Select::make('match_fields')
                            ->label('Поля для поиска дублей')
                            ->options(self::contactFieldOptions())
                            ->multiple()
                            ->searchable()
                            ->required(),

                        Select::make('master_strategy')
                            ->label('Главный контакт')
                            ->options([
                                Setting::STRATEGY_OLDEST => 'Самый старый',
                                Setting::STRATEGY_NEWEST => 'Самый новый',
                            ])
                            ->required(),

                        Toggle::make('auto_merge')
                            ->label('Автоматически объединять')
                            ->default(true),

                        TextInput::make('tag')
                            ->label('Тег для дублей')
                            ->helperText('Будет добавлен к контактам-дублям для ручной проверки.'),

                        Repeater::make('merge_rules')
                            ->label('Правила склейки по полям')
                            ->schema([
                                Select::make('field_id')
                                    ->label('Поле')
                                    ->options(self::contactFieldOptions())
                                    ->searchable()
                                    ->required(),

                                Select::make('rule')
                                    ->label('Правило')
                                    ->options([
                                        Setting::RULE_MERGE => 'Склеивать',
                                        Setting::RULE_KEEP_OLD => 'Оставить в старом',
                                        Setting::RULE_KEEP_NEW => 'Оставить в новом',
                                        Setting::RULE_SKIP => 'Не склеивать',
                                    ])
                                    ->required(),
                            ])
                            ->columns(2)
                            ->addActionLabel('+ Добавить правило')
                            ->collapsed(),
                    ])
                    ->columns([
                        'sm' => 2,
                        'lg' => null,
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'edit' => EditContactMerge::route('/{record}/edit'),
            'list' => ListContactMerge::route('/list'),
        ];
    }

    public static function getTransactions(): int
    {
        return Record::query()->count();
    }

    private static function contactFieldOptions(): array
    {
        $fields = Field::getContactSelectFields()->toArray();

        return ['name' => 'Имя'] + $fields;
    }
}
