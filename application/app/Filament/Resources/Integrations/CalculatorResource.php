<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\Resources\Integrations\CalculatorResource\Pages;
use App\Forms\Components\WorkflowMaskTextarea;
use App\Helpers\Traits\SettingResource;
use App\Helpers\Traits\TenantResource;
use App\Models\amoCRM\Field;
use App\Models\Integrations\Calculator\Setting;
use App\Models\Integrations\Calculator\Transaction;
use App\Support\Integrations\PricingView;
use App\Workflows\Actions\WorkflowTriggerConditionVariableCatalog;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;

class CalculatorResource extends Resource
{
    use TenantResource, SettingResource;

    protected static ?string $model = Setting::class;

    protected static ?string $slug = 'integrations/calculator';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?string $recordTitleAttribute = 'Калькулятор полей';

    public static function getTransactions(): int
    {
        return Transaction::query()
            ->where('user_id', \Illuminate\Support\Facades\Auth::id())
            ->count();
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

                        Section::make('Формулы')
                            ->description('Настройте правила расчета. Эти же выражения можно использовать в действии конструктора процессов.')
                            ->headerActions([
                                self::variablesAction('calculator_formula_variables'),
                            ])
                            ->schema([
                                Repeater::make('formulas')
                                    ->hiddenLabel()
                                    ->schema([
                                        Toggle::make('enabled')
                                            ->label('Активна')
                                            ->default(true),

                                        TextInput::make('name')
                                            ->label('Название')
                                            ->required()
                                            ->placeholder('Маржа сделки'),

                                        TextInput::make('group')
                                            ->label('Группа')
                                            ->placeholder('Финансы'),

                                        TextInput::make('priority')
                                            ->label('Приоритет')
                                            ->numeric()
                                            ->default(100)
                                            ->minValue(1)
                                            ->helperText('Чем меньше число, тем раньше считается формула.'),

                                        Forms\Components\Select::make('target_entity')
                                            ->label('Сущность')
                                            ->options([
                                                'lead' => 'Сделка',
                                                'contact' => 'Контакт',
                                                'company' => 'Компания',
                                            ])
                                            ->default('lead')
                                            ->required()
                                            ->live()
                                            ->native(false),

                                        Forms\Components\Select::make('result_field_id')
                                            ->label('Поле результата')
                                            ->options(fn(Get $get) => self::fieldOptions((string)($get('target_entity') ?: 'lead')))
                                            ->searchable()
                                            ->preload()
                                            ->required()
                                            ->native(false),

                                        TextInput::make('round_precision')
                                            ->label('Округление')
                                            ->numeric()
                                            ->default(2)
                                            ->minValue(0)
                                            ->maxValue(6)
                                            ->helperText('Количество знаков после запятой.'),

                                        WorkflowMaskTextarea::make('expression')
                                            ->label('Формула')
                                            ->rows(3)
                                            ->monospace()
                                            ->required()
                                            ->placeholder('({{lead.price}} - {{lead.cf_cost}}) / {{lead.price}} * 100')
                                            ->helperText('Введите {{, чтобы открыть подстановку переменных. Поддерживаются +, -, *, /, %, ^, скобки и функции round, ceil, floor, abs, min, max.'),
                                    ])
                                    ->columns(2)
                                    ->collapsible()
                                    ->itemLabel(fn(array $state): ?string => $state['name'] ?? 'Формула')
                                    ->defaultItems(1)
                                    ->reorderable(false)
                                    ->addActionLabel('+ Добавить формулу'),
                            ]),
                    ])
                    ->columnSpan(2),

                Section::make()
                    ->schema([
                        Action::make('docs')
                            ->label('Как работает')
                            ->url('https://cmdf5.ru/widjety-amocrm/calc-fields')
                            ->openUrlInNewTab(),

                        Section::make()
                            ->schema([
                                TextEntry::make('pricing')
                                    ->hiddenLabel()
                                    ->html()
                                    ->state(fn($model) => PricingView::sidebarHtml($model::$cost)),
                            ]),
                    ])
                    ->compact()
                    ->columnSpan(1),
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
            'edit' => Pages\EditCalculator::route('/{record}/edit'),
            'transactions' => Pages\ListTransactions::route('/transactions'),
        ];
    }

    public static function clearTransactions(int $days = 7): bool
    {
        return true;
    }

    private static function fieldOptions(string $entity): array
    {
        return match ($entity) {
            'contact' => Field::getContactSelectFields()->all(),
            'company' => Field::getCompanySelectFields()->all(),
            default => ['system:price' => 'Бюджет сделки'] + Field::getLeadSelectFields()->all(),
        };
    }

    private static function variablesAction(string $name): Action
    {
        return Action::make($name)
            ->label('Переменные')
            ->icon('heroicon-o-variable')
            ->color('gray')
            ->modalHeading('Справочник переменных и ID')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Закрыть')
            ->modalWidth('5xl')
            ->modalContent(fn() => view('filament.workflow-builder.mask-reference', [
                'groups' => WorkflowTriggerConditionVariableCatalog::groupedOptions(false),
                'systemIdGroups' => WorkflowTriggerConditionVariableCatalog::systemIdGroups(),
            ]));
    }
}
