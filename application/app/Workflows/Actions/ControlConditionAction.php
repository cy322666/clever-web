<?php

namespace App\Workflows\Actions;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
                ->description(static::workflowTrans('sections.conditions.description'))
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
                            static::conditionValueSelect('left', false)
                                ->label(static::workflowTrans('fields.left.label'))
                                ->required(),

                            Select::make('operator')
                                ->label(static::actionCommonTrans('fields.operator.label'))
                                ->options([
                                    'equals' => static::actionCommonTrans('operators.equals') . ' (==)',
                                    'not_equals' => static::actionCommonTrans('operators.not_equals') . ' (!=)',
                                    'strict_equals' => static::actionCommonTrans('operators.strict_equals') . ' (===)',
                                    'gt' => static::actionCommonTrans('operators.greater_than') . ' (>)',
                                    'gte' => static::actionCommonTrans('operators.greater_than_or_equal') . ' (>=)',
                                    'lt' => static::actionCommonTrans('operators.less_than') . ' (<)',
                                    'lte' => static::actionCommonTrans('operators.less_than_or_equal') . ' (<=)',
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
                                ->native(false),

                            static::conditionValueSelect('right', true)
                                ->label(static::workflowTrans('fields.right.label'))
                                ->visible(fn(Get $get): bool => !in_array($get('operator'), [
                                    'is_empty',
                                    'is_not_empty',
                                    'is_null',
                                    'is_not_null',
                                    'is_true',
                                    'is_false',
                                ], true)),
                        ])
                        ->columns(3)
                        ->addActionLabel(static::workflowTrans('fields.conditions.add'))
                        ->defaultItems(1)
                        ->reorderable(),
                ]),

            Section::make(static::workflowTrans('sections.branches.label'))
                ->description(static::workflowTrans('sections.branches.description'))
                ->schema([
                    Toggle::make('has_true_branch')
                        ->label(static::workflowTrans('fields.has_true_branch.label'))
                        ->default(true)
                        ->helperText(static::workflowTrans('fields.has_true_branch.helper')),

                    Toggle::make('has_false_branch')
                        ->label(static::workflowTrans('fields.has_false_branch.label'))
                        ->default(false)
                        ->helperText(static::workflowTrans('fields.has_false_branch.helper')),
                ])
                ->collapsed(),

            Section::make(static::actionCommonTrans('sections.output'))
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
                        ->visible(fn(Get $get): bool => (bool)$get('store_result'))
                        ->helperText(
                            static::styledHelperText(static::actionCommonTrans('messages.access_via', [
                                'variable' => static::codeVar('{{var.condition_result.branch}}'),
                            ]))
                        ),
                ]),
        ];
    }

    protected static function conditionValueSelect(string $name, bool $includeStaticValues): Select
    {
        return Select::make($name)
            ->options(F5TriggerConditionVariableCatalog::groupedOptions($includeStaticValues))
            ->getSearchResultsUsing(
                fn(string $search): array => F5TriggerConditionVariableCatalog::search($search, $includeStaticValues)
            )
            ->getOptionLabelUsing(
                fn(?string $value): ?string => F5TriggerConditionVariableCatalog::label($value, $includeStaticValues)
            )
            ->searchable()
            ->searchValues()
            ->native(false)
            ->placeholder('');
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

        return F5TriggerConditionVariableCatalog::label($value, true) ?? $value;
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
