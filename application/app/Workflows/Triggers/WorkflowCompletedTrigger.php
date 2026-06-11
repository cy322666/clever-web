<?php

namespace App\Workflows\Triggers;

use App\Models\Workflows\Workflow;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Leek\FilamentWorkflows\Triggers\Contracts\BaseTrigger;

class WorkflowCompletedTrigger implements BaseTrigger
{
    public static function type(): string
    {
        return 'workflow-completed';
    }

    public static function name(): string
    {
        return 'Запуск из другого процесса';
    }

    public static function description(): string
    {
        return 'Запускается действием из выбранного процесса.';
    }

    public static function icon(): string
    {
        return 'heroicon-o-arrow-right-circle';
    }

    public static function color(): string
    {
        return '#14B8A6';
    }

    /**
     * @return array<Component>
     */
    public static function configSchema(): array
    {
        return [
            Select::make('source_workflow_id')
                ->label('Родительский процесс')
                ->options(fn(): array => static::workflowOptions())
                ->searchable()
                ->native(false)
                ->required(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultConfig(): array
    {
        return [
            'source_workflow_id' => null,
        ];
    }

    public function shouldTrigger(array $config, mixed $subject, array $context = []): bool
    {
        $configuredWorkflowId = (int)($config['source_workflow_id'] ?? 0);
        $sourceWorkflowId = (int)($context['source_workflow_id'] ?? data_get($subject, 'workflow_id', 0));

        return $configuredWorkflowId > 0 && $sourceWorkflowId === $configuredWorkflowId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContextData(array $config, mixed $subject, array $context = []): array
    {
        return [
            'event' => static::type(),
            'source_workflow_id' => (int)($context['source_workflow_id'] ?? data_get($subject, 'workflow_id', 0)),
            'source_workflow_run_id' => (int)($context['source_workflow_run_id'] ?? data_get($subject, 'id', 0)),
            'triggered_at' => now()->toIso8601String(),
        ];
    }

    public static function getConfiguredDescription(array $config): string
    {
        $workflowName = static::workflowName($config['source_workflow_id'] ?? null);

        if ($workflowName !== null) {
            return '<strong>' . e($workflowName) . '</strong>';
        }

        return static::description();
    }

    /**
     * @return array{valid: bool, errors: array<string>}
     */
    public function validateConfig(array $config): array
    {
        $errors = [];
        $workflowId = (int)($config['source_workflow_id'] ?? 0);

        if ($workflowId <= 0) {
            $errors[] = 'Выберите процесс, который будет запускать этот процесс.';
        } elseif (static::workflowName($workflowId) === null) {
            $errors[] = 'Выбранный процесс не найден.';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function workflowOptions(): array
    {
        $workflowModel = config('filament-workflows.models.workflow', Workflow::class);
        $tenantColumn = config('filament-workflows.tenancy.column', 'user_id');

        return $workflowModel::query()
            ->when(
                config('filament-workflows.tenancy.enabled', false) && auth()->id(),
                fn($query) => $query->where($tenantColumn, auth()->id())
            )
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(fn(string $name, int|string $id): array => [(string)$id => $name])
            ->all();
    }

    private static function workflowName(mixed $workflowId): ?string
    {
        $workflowId = (int)$workflowId;

        if ($workflowId <= 0) {
            return null;
        }

        $workflowModel = config('filament-workflows.models.workflow', Workflow::class);
        $tenantColumn = config('filament-workflows.tenancy.column', 'user_id');

        return $workflowModel::query()
            ->when(
                config('filament-workflows.tenancy.enabled', false) && auth()->id(),
                fn($query) => $query->where($tenantColumn, auth()->id())
            )
            ->whereKey($workflowId)
            ->value('name');
    }
}
