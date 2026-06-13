<?php

namespace App\Workflows\Triggers;

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
        return 'Запускается действием «Запустить процесс» из другого процесса.';
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
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultConfig(): array
    {
        return [];
    }

    public function shouldTrigger(array $config, mixed $subject, array $context = []): bool
    {
        $sourceWorkflowId = (int)($context['source_workflow_id'] ?? data_get($subject, 'workflow_id', 0));

        return $sourceWorkflowId > 0;
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
        return static::description();
    }

    /**
     * @return array{valid: bool, errors: array<string>}
     */
    public function validateConfig(array $config): array
    {
        return [
            'valid' => true,
            'errors' => [],
        ];
    }
}
