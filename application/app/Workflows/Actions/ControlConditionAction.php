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
use Illuminate\Support\Facades\Log;
use Leek\FilamentWorkflows\Forms\Components\VariableTextInput;
use Leek\FilamentWorkflows\Actions\FlowControl\ConditionAction;
use Leek\FilamentWorkflows\Context\WorkflowContext;
use Throwable;

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
                                        true,
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

    /**
     * @param array<string, mixed> $config
     * @return array{success: bool, output?: array<string, mixed>, error?: string}
     */
    public function handle(array $config, ?WorkflowContext $context = null): array
    {
        $conditions = $config['conditions'] ?? [];
        $logic = $config['logic'] ?? 'and';

        if (empty($conditions)) {
            return [
                'success' => false,
                'error' => static::workflowTrans('errors.none_defined'),
            ];
        }

        try {
            $conditionResults = [];

            foreach ($conditions as $condition) {
                if (!is_array($condition)) {
                    continue;
                }

                $conditionResults[] = $this->evaluateConditionWithDetails($condition, $context);
            }

            $results = array_map(
                static fn(array $result): bool => (bool)$result['passed'],
                $conditionResults
            );

            $passed = $logic === 'and'
                ? !in_array(false, $results, true)
                : in_array(true, $results, true);

            $branch = $passed ? 'true' : 'false';

            Log::info('Workflow condition evaluated', [
                'logic' => $logic,
                'results' => $results,
                'branch' => $branch,
            ]);

            $output = [
                'passed' => $passed,
                'branch' => $branch,
                'condition_results' => $conditionResults,
                'true_actions' => $config['true_actions'] ?? [],
                'false_actions' => $config['false_actions'] ?? [],
            ];

            if (($config['store_result'] ?? true) && $context) {
                $contextKey = $config['context_key'] ?? 'condition_result';
                $context->setVariable($contextKey, [
                    'passed' => $passed,
                    'branch' => $branch,
                    'condition_results' => $conditionResults,
                ]);
            }

            return [
                'success' => true,
                'output' => $output,
            ];
        } catch (Throwable $e) {
            Log::error('Workflow condition evaluation failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => static::workflowTrans('errors.evaluation_failed', ['error' => $e->getMessage()]),
            ];
        }
    }

    /**
     * @param array<string, mixed> $condition
     * @return array<string, mixed>
     */
    protected function evaluateConditionWithDetails(array $condition, ?WorkflowContext $context): array
    {
        $leftRaw = $condition['left'] ?? '';
        $operator = (string)($condition['operator'] ?? 'equals');
        $rightRaw = $condition['right'] ?? '';

        $left = $context ? $context->resolve($leftRaw) : $leftRaw;
        $right = $context ? $context->resolve($rightRaw) : $rightRaw;

        $passed = match ($operator) {
            'equals' => $left == $right,
            'not_equals' => $left != $right,
            'strict_equals' => $left === $right,
            'gt' => $this->compareNumeric($left, $right, '>'),
            'gte' => $this->compareNumeric($left, $right, '>='),
            'lt' => $this->compareNumeric($left, $right, '<'),
            'lte' => $this->compareNumeric($left, $right, '<='),
            'contains' => is_string($left) && str_contains($left, (string)$right),
            'not_contains' => is_string($left) && !str_contains($left, (string)$right),
            'starts_with' => is_string($left) && str_starts_with($left, (string)$right),
            'ends_with' => is_string($left) && str_ends_with($left, (string)$right),
            'in' => $this->isInArray($left, $right),
            'not_in' => !$this->isInArray($left, $right),
            'is_empty' => $this->isEmpty($left),
            'is_not_empty' => !$this->isEmpty($left),
            'is_null' => $left === null || $left === '',
            'is_not_null' => $left !== null && $left !== '',
            'is_true' => $this->isTruthy($left),
            'is_false' => !$this->isTruthy($left),
            'matches' => is_string($left) && is_string($right) && (bool)preg_match($right, $left),
            default => false,
        };

        return [
            'passed' => $passed,
            'left' => $leftRaw,
            'left_value' => $this->normalizeConditionOutputValue($left),
            'operator' => $operator,
            'right' => $rightRaw,
            'right_value' => $this->normalizeConditionOutputValue($right),
        ];
    }

    protected function normalizeConditionOutputValue(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return json_decode(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null', true);
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
                Hidden::make($name . '_status_pipeline_id'),
                Select::make($name . '_source')
                    ->label($label)
                    ->options(static::conditionValueSourceOptions($includeStaticValues))
                    ->default('mask')
                    ->live()
                    ->native(false)
                    ->afterStateHydrated(
                        function (?string $state, Set $set, Get $get) use ($name, $includeStaticValues): void {
                            $value = trim((string)$get($name));
                            $inferredSource = static::conditionValueSource($value, $includeStaticValues);
                            $source = static::normalizeConditionValueSource($state);

                            if ($source === null || ($source === 'mask' && $value !== '' && $inferredSource !== 'mask')) {
                                $source = $inferredSource;
                            }

                            $set($name . '_source', $source);
                            $set($name . '_mask', $source === 'mask' ? $value : null);
                            $set($name . '_amo_field', $source === 'amo_field' ? $value : null);
                            $set($name . '_amo_pipeline', $source === 'amo_pipeline' ? $value : null);
                            $set(
                                $name . '_amo_status',
                                $source === 'amo_status'
                                    ? static::amoStatusSelectValue($value, $get($name . '_status_pipeline_id'))
                                    : null
                            );
                            $set($name . '_static', $source === 'static' ? $value : null);
                            $set($name . '_manual', $source === 'manual' ? $value : null);
                            $set($name . '_number', $source === 'number' ? $value : null);
                            $set($name . '_list', $source === 'list' ? $value : null);
                            $set($name . '_regex', $source === 'regex' ? $value : null);
                        }
                    )
                    ->afterStateUpdated(function (?string $state, Set $set, Get $get) use ($name): void {
                        if ($state === 'amo_status') {
                            [$pipelineId, $statusId] = static::parseAmoStatusSelectValue($get($name . '_amo_status'));

                            $set($name, $statusId);
                            $set($name . '_status_pipeline_id', $pipelineId);

                            return;
                        }

                        $set($name, (string)$get($name . '_' . ($state ?: 'mask')));
                    }),
                VariableTextInput::make($name . '_manual')
                    ->label('Значение')
                    ->dehydrated(false)
                    ->visible(fn(Get $get): bool => $get($name . '_source') === 'manual')
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn(?string $state, Set $set): mixed => $set($name, $state)),
                TextInput::make($name . '_number')
                    ->label('Число')
                    ->numeric()
                    ->dehydrated(false)
                    ->visible(fn(Get $get): bool => $get($name . '_source') === 'number')
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn(?string $state, Set $set): mixed => $set($name, $state)),
                VariableTextInput::make($name . '_list')
                    ->label('Список')
                    ->dehydrated(false)
                    ->visible(fn(Get $get): bool => $get($name . '_source') === 'list')
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn(?string $state, Set $set): mixed => $set($name, $state)),
                TextInput::make($name . '_regex')
                    ->label('Регулярное выражение')
                    ->dehydrated(false)
                    ->visible(fn(Get $get): bool => $get($name . '_source') === 'regex')
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn(?string $state, Set $set): mixed => $set($name, $state)),
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
                static::conditionAmoPipelineSelect($name . '_amo_pipeline')
                    ->label('Воронка')
                    ->dehydrated(false)
                    ->visible(fn(Get $get): bool => $get($name . '_source') === 'amo_pipeline')
                    ->live()
                    ->afterStateUpdated(fn(?string $state, Set $set): mixed => $set($name, $state)),
                static::conditionAmoStatusSelect($name . '_amo_status')
                    ->label('Этап')
                    ->dehydrated(false)
                    ->visible(fn(Get $get): bool => $get($name . '_source') === 'amo_status')
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set) use ($name): void {
                        [$pipelineId, $statusId] = static::parseAmoStatusSelectValue($state);

                        $set($name, $statusId);
                        $set($name . '_status_pipeline_id', $pipelineId);
                    }),
                VariableTextInput::make($name . '_static')
                    ->label('Своё значение')
                    ->dehydrated(false)
                    ->visible(fn(Get $get): bool => $includeStaticValues && $get($name . '_source') === 'static')
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
            'manual' => 'Текстовое значение',
            'number' => 'Числовое значение',
            'list' => 'Список своих значений',
            'regex' => 'Регулярное выражение',
            'amo_field' => 'Поле',
            'amo_pipeline' => 'Воронка',
            'amo_status' => 'Этап',
        ];

        if ($includeStaticValues) {
            $options = [
                'mask' => 'Переменная',
                'manual' => 'Текстовое значение',
                'number' => 'Числовое значение',
                'list' => 'Список своих значений',
                'regex' => 'Регулярное выражение',
                'amo_field' => 'Поле',
                'amo_pipeline' => 'Воронка',
                'amo_status' => 'Этап',
                'static' => 'Готовое значение',
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

    protected static function conditionAmoPipelineSelect(string $name): Select
    {
        return Select::make($name)
            ->options(WorkflowTriggerConditionVariableCatalog::groupedAmoPipelineOptions())
            ->getSearchResultsUsing(
                fn(string $search): array => WorkflowTriggerConditionVariableCatalog::searchAmoPipelines($search)
            )
            ->getOptionLabelUsing(
                fn(?string $value): ?string => $value !== null
                    ? (WorkflowTriggerConditionVariableCatalog::amoPipelineLabel($value) ?? $value)
                    : null
            )
            ->searchable()
            ->searchValues()
            ->native(false)
            ->placeholder('');
    }

    protected static function conditionAmoStatusSelect(string $name): Select
    {
        return Select::make($name)
            ->options(WorkflowTriggerConditionVariableCatalog::groupedAmoStatusConditionOptions())
            ->getSearchResultsUsing(
                fn(string $search): array => WorkflowTriggerConditionVariableCatalog::searchAmoStatusConditions($search)
            )
            ->getOptionLabelUsing(
                fn(?string $value): ?string => $value !== null
                    ? (
                        WorkflowTriggerConditionVariableCatalog::flatAmoStatusConditionOptions()[$value]
                        ?? WorkflowTriggerConditionVariableCatalog::amoStatusLabel($value)
                        ?? $value
                    )
                    : null
            )
            ->searchable()
            ->searchValues()
            ->native(false)
            ->placeholder('');
    }

    protected static function amoStatusSelectValue(string $statusId, mixed $pipelineId = null): string
    {
        $statusId = trim($statusId);
        $pipelineId = trim((string)$pipelineId);

        return $pipelineId !== '' ? $pipelineId . '.' . $statusId : $statusId;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    protected static function parseAmoStatusSelectValue(?string $value): array
    {
        $value = trim((string)$value);

        if ($value === '') {
            return [null, null];
        }

        if (!str_contains($value, '.')) {
            return [null, $value];
        }

        [$pipelineId, $statusId] = explode('.', $value, 2);

        $pipelineId = trim($pipelineId);
        $statusId = trim($statusId);

        return [
            $pipelineId !== '' ? $pipelineId : null,
            $statusId !== '' ? $statusId : null,
        ];
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

        if (array_key_exists($value, WorkflowTriggerConditionVariableCatalog::flatAmoPipelineOptions())) {
            return 'amo_pipeline';
        }

        if (array_key_exists($value, WorkflowTriggerConditionVariableCatalog::flatAmoStatusOptions())) {
            return 'amo_status';
        }

        if ($includeStaticValues && array_key_exists(
                $value,
                WorkflowTriggerConditionVariableCatalog::staticValueOptions()
            )) {
            return 'static';
        }

        return 'manual';
    }

    protected static function normalizeConditionValueSource(?string $source): ?string
    {
        return in_array($source, ['mask', 'amo_field', 'amo_pipeline', 'amo_status', 'static', 'manual', 'number', 'list', 'regex'], true)
            ? $source
            : null;
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
            $left = static::humanConditionValue($condition['left'] ?? '', $condition, $conditions, 'left');
            $operator = $condition['operator'] ?? 'equals';
            $operatorLabel = static::humanOperator((string)$operator);
            $right = static::humanConditionValue($condition['right'] ?? '', $condition, $conditions, 'right');

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

    /**
     * @param array<string, mixed>|null $condition
     * @param array<int, array<string, mixed>> $conditions
     */
    protected static function humanConditionValue(
        mixed $value,
        ?array $condition = null,
        array $conditions = [],
        ?string $side = null,
    ): string
    {
        $value = trim((string)$value);

        if ($value === '') {
            return '-';
        }

        $pipelineId = filled($condition[$side . '_status_pipeline_id'] ?? null)
            ? (string)$condition[$side . '_status_pipeline_id']
            : static::pipelineIdFromConditions($conditions);

        if (
            $pipelineId !== null
            && $condition !== null
            && $side !== null
            && static::isStatusComparisonValue($condition, $side)
        ) {
            return WorkflowTriggerConditionVariableCatalog::amoStatusName($value, $pipelineId)
                ?? (WorkflowTriggerConditionVariableCatalog::label($value, true) ?? $value);
        }

        return WorkflowTriggerConditionVariableCatalog::label($value, true) ?? $value;
    }

    /**
     * @param array<int, array<string, mixed>> $conditions
     */
    protected static function pipelineIdFromConditions(array $conditions): ?string
    {
        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                continue;
            }

            $operator = (string)($condition['operator'] ?? 'equals');

            if (!in_array($operator, ['equals', 'strict_equals'], true)) {
                continue;
            }

            foreach ([['left', 'right'], ['right', 'left']] as [$pipelineSide, $valueSide]) {
                if (!static::isPipelineSide($condition, $pipelineSide)) {
                    continue;
                }

                $pipelineId = trim((string)($condition[$valueSide] ?? ''));

                if ($pipelineId !== '' && !str_contains($pipelineId, '{{')) {
                    return $pipelineId;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $condition
     */
    protected static function isStatusComparisonValue(array $condition, string $side): bool
    {
        if (($condition[$side . '_source'] ?? null) === 'amo_status') {
            return true;
        }

        $value = (string)($condition[$side] ?? '');
        $oppositeSide = $side === 'left' ? 'right' : 'left';

        return static::isStatusSide($condition, $oppositeSide)
            && !str_contains($value, '{{')
            && !str_contains($value, 'status_id');
    }

    /**
     * @param array<string, mixed> $condition
     */
    protected static function isPipelineSide(array $condition, string $side): bool
    {
        $source = (string)($condition[$side . '_source'] ?? '');
        $value = (string)($condition[$side] ?? '');

        return $source === 'amo_pipeline'
            || str_contains($value, 'pipeline_id');
    }

    /**
     * @param array<string, mixed> $condition
     */
    protected static function isStatusSide(array $condition, string $side): bool
    {
        $source = (string)($condition[$side . '_source'] ?? '');
        $value = (string)($condition[$side] ?? '');

        return $source === 'amo_status'
            || str_contains($value, 'status_id');
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
