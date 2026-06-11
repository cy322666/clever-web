<?php

namespace App\Workflows\Actions;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Leek\FilamentWorkflows\Forms\Components\VariableTextInput;
use Leek\FilamentWorkflows\Actions\FlowControl\ConditionAction;

class ControlConditionAction extends ConditionAction
{
    /**
     * @return array<Component>
     */
    public static function workflowConfigSchema(?string $modelClass = null): array
    {
        return [
            Section::make(static::workflowTrans('sections.conditions.label'))
                ->compact()
                ->schema([
                    Select::make('logic')
                        ->label(static::workflowTrans('fields.logic.label'))
                        ->options([
                            'and' => static::workflowTrans('logic.and'),
                            'or' => static::workflowTrans('logic.or'),
                        ])
                        ->default('and')
                        ->required()
                        ->native(false),

                    Repeater::make('conditions')
                        ->label(static::workflowTrans('fields.conditions.label'))
                        ->schema([
                            Grid::make(12)
                                ->schema([
                                    static::conditionValueInput(
                                        'left',
                                        false,
                                        static::workflowTrans('fields.left.label')
                                    )
                                        ->columnSpan(4),

                                    Select::make('operator')
                                        ->label(static::actionCommonTrans('fields.operator.label'))
                                        ->options([
                                            'equals' => static::actionCommonTrans('operators.equals') . ' (==)',
                                            'not_equals' => static::actionCommonTrans('operators.not_equals') . ' (!=)',
                                            'strict_equals' => static::actionCommonTrans(
                                                    'operators.strict_equals'
                                                ) . ' (===)',
                                            'gt' => static::actionCommonTrans('operators.greater_than') . ' (>)',
                                            'gte' => static::actionCommonTrans(
                                                    'operators.greater_than_or_equal'
                                                ) . ' (>=)',
                                            'lt' => static::actionCommonTrans('operators.less_than') . ' (<)',
                                            'lte' => static::actionCommonTrans(
                                                    'operators.less_than_or_equal'
                                                ) . ' (<=)',
                                            'contains' => static::actionCommonTrans('operators.contains'),
                                            'not_contains' => static::actionCommonTrans('operators.not_contains'),
                                            'starts_with' => static::actionCommonTrans('operators.starts_with'),
                                            'ends_with' => static::actionCommonTrans('operators.ends_with'),
                                            'in' => static::actionCommonTrans('operators.in_array'),
                                            'not_in' => static::actionCommonTrans('operators.not_in_array'),
                                            'is_empty' => static::actionCommonTrans('operators.is_empty'),
                                            'is_not_empty' => static::actionCommonTrans('operators.is_not_empty'),
                                            'is_null' => static::actionCommonTrans('operators.is_null'),
                                            'is_not_null' => static::actionCommonTrans('operators.is_not_null'),
                                            'is_true' => static::actionCommonTrans('operators.is_true'),
                                            'is_false' => static::actionCommonTrans('operators.is_false'),
                                            'matches' => static::actionCommonTrans('operators.matches_regex'),
                                        ])
                                        ->default('equals')
                                        ->required()
                                        ->live()
                                        ->native(false)
                                        ->columnSpan(4),

                                    static::conditionValueInput(
                                        'right',
                                        true,
                                        static::workflowTrans('fields.right.label')
                                    )
                                        ->visible(fn(Get $get): bool => !in_array($get('operator'), [
                                            'is_empty',
                                            'is_not_empty',
                                            'is_null',
                                            'is_not_null',
                                            'is_true',
                                            'is_false',
                                        ], true))
                                        ->columnSpan(4),
                                ])
                        ])
                        ->columns(1)
                        ->addActionLabel(static::workflowTrans('fields.conditions.add'))
                        ->defaultItems(1),
                ]),

            Section::make(static::workflowTrans('sections.branches.label'))
                ->compact()
                ->schema([
                    Toggle::make('has_true_branch')
                        ->label(static::workflowTrans('fields.has_true_branch.label'))
                        ->default(true),

                    Toggle::make('has_false_branch')
                        ->label(static::workflowTrans('fields.has_false_branch.label'))
                        ->default(false),
                ])
                ->collapsed(),

            Section::make(static::actionCommonTrans('sections.output'))
                ->compact()
                ->collapsed()
                ->schema([
                    Toggle::make('store_result')
                        ->label(static::workflowTrans('fields.store_result.label'))
                        ->default(true)
                        ->live(),

                    TextInput::make('context_key')
                        ->label(static::actionCommonTrans('fields.context_key.label'))
                        ->placeholder('condition_result')
                        ->default('condition_result')
                        ->visible(fn(Get $get): bool => (bool)$get('store_result')),
                ]),
        ];
    }

    protected static function conditionValueSelect(string $name, bool $includeStaticValues): Select
    {
        return Select::make($name)
            ->options(WorkflowTriggerConditionVariableCatalog::groupedOptions($includeStaticValues))
            ->getSearchResultsUsing(
                fn(string $search): array => WorkflowTriggerConditionVariableCatalog::search(
                    $search,
                    $includeStaticValues
                )
            )
            ->getOptionLabelUsing(
                fn(?string $value): ?string => WorkflowTriggerConditionVariableCatalog::label(
                    $value,
                    $includeStaticValues
                )
            )
            ->searchable()
            ->searchValues()
            ->native(false)
            ->placeholder('');
    }

    protected static function conditionValueInput(string $name, bool $includeStaticValues, string $label): Grid
    {
        return Grid::make(1)
            ->schema([
                Hidden::make($name),
                Select::make($name . '_source')
                    ->label($label)
                    ->options(static::conditionValueSourceOptions($includeStaticValues))
                    ->default('mask')
                    ->dehydrated(false)
                    ->live()
                    ->native(false)
                    ->afterStateHydrated(
                        function (?string $state, Set $set, Get $get) use ($name, $includeStaticValues): void {
                            $value = trim((string)$get($name));
                            $source = static::conditionValueSource($value, $includeStaticValues);

                            $set($name . '_source', $source);
                            $set($name . '_mask', $source === 'mask' ? $value : null);
                            $set($name . '_amo_field', $source === 'amo_field' ? $value : null);
                            $set($name . '_static', $source === 'static' ? $value : null);
                            $set($name . '_manual', $source === 'manual' ? $value : null);
                        }
                    )
                    ->afterStateUpdated(function (?string $state, Set $set, Get $get) use ($name): void {
                        $set($name, (string)$get($name . '_' . ($state ?: 'mask')));
                    }),
                static::conditionMaskSelect($name . '_mask')
                    ->label('Значение')
                    ->dehydrated(false)
                    ->visible(fn(Get $get): bool => $get($name . '_source') === 'mask')
                    ->live()
                    ->afterStateUpdated(fn(?string $state, Set $set): mixed => $set($name, $state)),
                static::conditionAmoFieldSelect($name . '_amo_field')
                    ->label('Поле')
                    ->dehydrated(false)
                    ->visible(fn(Get $get): bool => $get($name . '_source') === 'amo_field')
                    ->live()
                    ->afterStateUpdated(fn(?string $state, Set $set): mixed => $set($name, $state)),
                VariableTextInput::make($name . '_static')
                    ->label('Своё значение')
                    ->dehydrated(false)
                    ->visible(fn(Get $get): bool => $includeStaticValues && $get($name . '_source') === 'static')
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn(?string $state, Set $set): mixed => $set($name, $state)),
                VariableTextInput::make($name . '_manual')
                    ->label('Значение')
                    ->dehydrated(false)
                    ->visible(fn(Get $get): bool => $get($name . '_source') === 'manual')
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn(?string $state, Set $set): mixed => $set($name, $state)),
            ]);
    }

    /**
     * @return array<string, string>
     */
    protected static function conditionValueSourceOptions(bool $includeStaticValues): array
    {
        $options = [
            'mask' => 'Переменная',
            'amo_field' => 'Поле amoCRM',
            'manual' => 'Вручную',
        ];

        if ($includeStaticValues) {
            $options = [
                'mask' => 'Переменная',
                'amo_field' => 'Поле amoCRM',
                'static' => 'Готовое значение',
                'manual' => 'Вручную',
            ];
        }

        return $options;
    }

    protected static function conditionMaskSelect(string $name): Select
    {
        return Select::make($name)
            ->options(WorkflowTriggerConditionVariableCatalog::groupedMaskOptions())
            ->getSearchResultsUsing(
                fn(string $search): array => WorkflowTriggerConditionVariableCatalog::searchMasks($search)
            )
            ->getOptionLabelUsing(
                fn(?string $value): ?string => WorkflowTriggerConditionVariableCatalog::flatMaskOptions(
                )[$value] ?? $value
            )
            ->searchable()
            ->searchValues()
            ->native(false)
            ->placeholder('');
    }

    protected static function conditionAmoFieldSelect(string $name): Select
    {
        return Select::make($name)
            ->options(WorkflowTriggerConditionVariableCatalog::groupedAmoFieldOptions())
            ->getSearchResultsUsing(
                fn(string $search): array => WorkflowTriggerConditionVariableCatalog::searchAmoFields($search)
            )
            ->getOptionLabelUsing(
                fn(?string $value): ?string => WorkflowTriggerConditionVariableCatalog::flatAmoFieldOptions(
                )[$value] ?? $value
            )
            ->searchable()
            ->searchValues()
            ->native(false)
            ->placeholder('');
    }

    protected static function conditionValueSource(string $value, bool $includeStaticValues): string
    {
        if ($value === '') {
            return 'mask';
        }

        if (array_key_exists($value, WorkflowTriggerConditionVariableCatalog::flatMaskOptions())) {
            return 'mask';
        }

        if (array_key_exists($value, WorkflowTriggerConditionVariableCatalog::flatAmoFieldOptions())) {
            return 'amo_field';
        }

        if ($includeStaticValues && array_key_exists(
                $value,
                WorkflowTriggerConditionVariableCatalog::staticValueOptions()
            )) {
            return 'static';
        }

        return 'manual';
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function getConfiguredDescription(array $config): string
    {
        $conditions = $config['conditions'] ?? [];
        $count = count($conditions);

        if ($count === 0) {
            return static::workflowDescription();
        }

        $logic = ($config['logic'] ?? 'and') === 'or' ? 'ИЛИ' : 'И';

        if ($count === 1) {
            $condition = $conditions[0] ?? [];
            $left = static::humanConditionValue($condition['left'] ?? '');
            $operator = $condition['operator'] ?? 'equals';
            $operatorLabel = static::humanOperator((string)$operator);
            $right = static::humanConditionValue($condition['right'] ?? '');

            if (in_array(
                $operator,
                ['is_empty', 'is_not_empty', 'is_null', 'is_not_null', 'is_true', 'is_false'],
                true
            )) {
                return '<strong>Если</strong> ' . e($left) . ' ' . e($operatorLabel);
            }

            return '<strong>Если</strong> ' . e($left) . ' ' . e($operatorLabel) . ' ' . e($right);
        }

        return static::workflowTrans('configured.if_many', [
            'count' => $count,
            'logic' => $logic,
        ]);
    }

    protected static function humanConditionValue(mixed $value): string
    {
        $value = trim((string)$value);

        if ($value === '') {
            return '-';
        }

        return WorkflowTriggerConditionVariableCatalog::label($value, true) ?? $value;
    }

    protected static function humanOperator(string $operator): string
    {
        return [
            'equals' => 'равно',
            'not_equals' => 'не равно',
            'strict_equals' => 'строго равно',
            'gt' => 'больше',
            'gte' => 'больше или равно',
            'lt' => 'меньше',
            'lte' => 'меньше или равно',
            'contains' => 'содержит',
            'not_contains' => 'не содержит',
            'starts_with' => 'начинается с',
            'ends_with' => 'заканчивается на',
            'in' => 'в списке',
            'not_in' => 'не в списке',
            'is_empty' => 'пусто',
            'is_not_empty' => 'не пусто',
            'is_null' => 'не заполнено',
            'is_not_null' => 'заполнено',
            'is_true' => 'истина',
            'is_false' => 'ложь',
            'matches' => 'соответствует шаблону',
        ][$operator] ?? $operator;
    }
}
