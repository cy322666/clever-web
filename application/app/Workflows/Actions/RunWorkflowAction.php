<?php

declare(strict_types=1);

namespace App\Workflows\Actions;

use App\Workflows\Triggers\WorkflowCompletedTrigger;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Leek\FilamentWorkflows\Actions\FlowControl\RunWorkflowAction as BaseRunWorkflowAction;
use Leek\FilamentWorkflows\Context\WorkflowContext;

class RunWorkflowAction extends BaseRunWorkflowAction
{
    /**
     * @return array<Component>
     */
    public static function workflowConfigSchema(?string $modelClass = null): array
    {
        return [
            Section::make('Процесс')
                ->description('Выберите процесс, который нужно запустить следующим шагом.')
                ->schema([
                    Select::make('workflow_id')
                        ->label('Процесс')
                        ->options(
                            fn($record = null, $livewire = null): array => static::workflowOptionsExceptCurrent(
                                $record,
                                $livewire
                            )
                        )
                        ->required()
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->helperText('Показываются процессы с триггером «Запуск из другого процесса».'),
                ]),

            Section::make('Что передать')
                ->description('Настройте, какие данные текущего запуска будут доступны дочернему процессу.')
                ->schema([
                    Toggle::make('pass_context')
                        ->label('Передать данные текущего процесса')
                        ->default(true)
                        ->live()
                        ->helperText('Если выключить, дочерний процесс запустится без контекста текущего запуска.'),

                    Toggle::make('pass_trigger_model')
                        ->label('Модель триггера')
                        ->default(true)
                        ->visible(fn(Get $get): bool => (bool)$get('pass_context')),

                    Toggle::make('pass_step_outputs')
                        ->label('Результаты предыдущих шагов')
                        ->default(true)
                        ->visible(fn(Get $get): bool => (bool)$get('pass_context')),

                    Toggle::make('pass_variables')
                        ->label('Переменные процесса')
                        ->default(true)
                        ->visible(fn(Get $get): bool => (bool)$get('pass_context')),
                ])
                ->columns(2)
                ->collapsed(),

            Section::make('Выполнение')
                ->description('Ограничения и поведение при ошибке дочернего процесса.')
                ->schema([
                    Toggle::make('wait_for_completion')
                        ->label('Дождаться завершения')
                        ->default(false)
                        ->helperText('Текущий процесс будет ждать результат дочернего процесса.'),

                    Toggle::make('fail_on_child_failure')
                        ->label('Остановить при ошибке дочернего процесса')
                        ->default(true),

                    TextInput::make('max_depth')
                        ->label('Максимальная глубина цепочки')
                        ->numeric()
                        ->default(static::MAX_CHAIN_DEPTH)
                        ->minValue(1)
                        ->maxValue(10)
                        ->helperText('Защита от бесконечного запуска процессов друг из друга.'),
                ])
                ->columns(2)
                ->collapsed(),

            Section::make('Результат')
                ->schema([
                    Toggle::make('store_result')
                        ->label('Сохранить результат в контекст')
                        ->default(true)
                        ->live(),

                    TextInput::make('context_key')
                        ->label('Ключ контекста')
                        ->placeholder('child_workflow')
                        ->default('child_workflow')
                        ->visible(fn(Get $get): bool => (bool)$get('store_result'))
                        ->helperText('Например: {{var.child_workflow.run_id}}'),
                ])
                ->columns(2)
                ->collapsed(),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{success: bool, output?: array<string, mixed>, error?: string}
     */
    public function handle(array $config, ?WorkflowContext $context = null): array
    {
        $targetWorkflowId = (int)($config['workflow_id'] ?? 0);
        $currentWorkflowId = (int)($context?->getWorkflowId() ?? 0);

        if ($targetWorkflowId > 0 && $currentWorkflowId > 0 && $targetWorkflowId === $currentWorkflowId) {
            return [
                'success' => false,
                'error' => 'Нельзя запускать текущий процесс из самого себя.',
            ];
        }

        if ($targetWorkflowId > 0 && !static::isCallableWorkflow($targetWorkflowId, $currentWorkflowId ?: null)) {
            return [
                'success' => false,
                'error' => 'Выбранный процесс не настроен для запуска из другого процесса.',
            ];
        }

        $result = parent::handle($config, $context);

        if (($result['success'] ?? false) && isset($result['output']['child_context']) && is_array(
                $result['output']['child_context']
            )) {
            $result['output']['child_context']['source_workflow_id'] = $currentWorkflowId ?: null;
            $result['output']['child_context']['source_workflow_run_id'] = $context?->getWorkflowRunId();
        }

        return $result;
    }

    /**
     * @return array<int|string, string>
     */
    protected static function workflowOptionsExceptCurrent(mixed $record = null, mixed $livewire = null): array
    {
        $currentWorkflowId = static::currentWorkflowId($record) ?? static::currentWorkflowId($livewire);
        $workflowModel = config('filament-workflows.models.workflow');
        $tenantColumn = config('filament-workflows.tenancy.column', 'user_id');

        return $workflowModel::query()
            ->when(
                config('filament-workflows.tenancy.enabled', false) && auth()->id(),
                fn($query) => $query->where($tenantColumn, auth()->id())
            )
            ->when($currentWorkflowId !== null, fn($query) => $query->whereKeyNot($currentWorkflowId))
            ->where('definition->trigger->type', WorkflowCompletedTrigger::type())
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->mapWithKeys(fn($workflow): array => [
                (string)$workflow->getKey() => $workflow->name . ($workflow->is_active ? '' : ' (выключен)'),
            ])
            ->all();
    }

    public function validateWorkflowConfig(array $config): array
    {
        $result = parent::validateWorkflowConfig($config);
        $workflowId = (int)($config['workflow_id'] ?? 0);

        if ($workflowId > 0 && !static::isCallableWorkflow($workflowId)) {
            $result['valid'] = false;
            $result['errors'][] = 'Выбранный процесс должен иметь триггер «Запуск из другого процесса».';
        }

        return $result;
    }

    protected static function currentWorkflowId(mixed $source): ?int
    {
        if (!is_object($source)) {
            return null;
        }

        if (method_exists($source, 'getKey')) {
            $key = $source->getKey();

            return is_numeric($key) ? (int)$key : null;
        }

        if (method_exists($source, 'getRecord')) {
            return static::currentWorkflowId($source->getRecord());
        }

        return null;
    }

    protected static function isCallableWorkflow(int $workflowId, ?int $sourceWorkflowId = null): bool
    {
        $workflowModel = config('filament-workflows.models.workflow');
        $tenantColumn = config('filament-workflows.tenancy.column', 'user_id');
        $sourceTenantId = $sourceWorkflowId !== null
            ? $workflowModel::query()->whereKey($sourceWorkflowId)->value($tenantColumn)
            : null;

        return $workflowModel::query()
            ->when(
                config('filament-workflows.tenancy.enabled', false),
                fn($query) => $query->when(
                    $sourceTenantId !== null,
                    fn($query) => $query->where($tenantColumn, $sourceTenantId),
                    fn($query) => $query->when(auth()->id(), fn($query) => $query->where($tenantColumn, auth()->id()))
                )
            )
            ->whereKey($workflowId)
            ->where('definition->trigger->type', WorkflowCompletedTrigger::type())
            ->exists();
    }
}
