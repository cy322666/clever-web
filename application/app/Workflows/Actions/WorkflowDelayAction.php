<?php

namespace App\Workflows\Actions;

use App\Forms\Components\WorkflowValueInput;
use Illuminate\Support\Sleep;
use Leek\FilamentWorkflows\Concerns\WorkflowAction;
use Leek\FilamentWorkflows\Context\WorkflowContext;

class WorkflowDelayAction
{
    use WorkflowAction;

    public const MIN_SECONDS = 0;
    public const MAX_SECONDS = 15;

    public static function validSeconds(mixed $seconds): bool
    {
        return (is_int($seconds) || (is_string($seconds) && ctype_digit($seconds)))
            && (int) $seconds >= self::MIN_SECONDS && (int) $seconds <= self::MAX_SECONDS;
    }

    public static function workflowType(): string
    {
        return 'workflow_delay';
    }

    public static function workflowName(): string
    {
        return 'Задержка';
    }

    public static function workflowDescription(): string
    {
        return 'Пауза от 0 до 15 секунд';
    }

    public static function workflowIcon(): string
    {
        return 'heroicon-o-clock';
    }

    public static function workflowCategory(): string
    {
        return 'Управление потоком';
    }

    public static function workflowDefaultConfig(): array
    {
        return ['seconds' => 5];
    }

    public static function workflowConfigSchema(?string $modelClass = null): array
    {
        return [WorkflowValueInput::make('seconds')->label('Секунды')->default(5)->required()
            ->type('number')->inputMode('numeric')->step(1)
            ->extraInputAttributes(['min' => self::MIN_SECONDS, 'max' => self::MAX_SECONDS])
            ->placeholder('От 0 до 15')
            ->rules([fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && str_contains($value, '{{')) {
                    return;
                }
                if (! self::validSeconds($value)) {
                    $fail('Укажите целое число от 0 до 15 секунд.');
                }
            }])];
    }

    public function handle(array $config, ?WorkflowContext $context = null): array
    {
        $seconds = array_key_exists('seconds', $config) ? $config['seconds'] : 5;
        $seconds = $context ? $context->resolve($seconds) : $seconds;
        if (! self::validSeconds($seconds)) {
            return ['success' => false, 'error' => 'Задержка должна быть целым числом от 0 до 15 секунд.'];
        }
        $preview = (bool) ($context?->getVariable('_dry_run') || $context?->getVariable('_test_mode'));
        if (! $preview && (int) $seconds > 0) {
            Sleep::for((int) $seconds)->seconds();
        }
        $outputs = $context?->getStepOutputs() ?? [];

        return ['success' => true, 'delay_seconds' => (int) $seconds, 'simulated' => $preview,
            'output' => $context instanceof \App\Workflows\Context\WorkflowContext
                ? $context->getNodeInput() : ($outputs ? end($outputs) : ($context?->getTriggerData() ?? []))];
    }
}
