<?php

namespace App\Workflows\Actions;

use App\Forms\Components\WorkflowValueInput;
use Illuminate\Support\Sleep;
use Leek\FilamentWorkflows\Concerns\WorkflowAction;
use Leek\FilamentWorkflows\Context\WorkflowContext;

class WorkflowDelayAction
{
    use WorkflowAction;

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
        return 'Пауза до 30 секунд';
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
            ->extraInputAttributes(['min' => 1, 'max' => 30])
            ->placeholder('От 1 до 30')
            ->rules([fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && str_contains($value, '{{')) {
                    return;
                }
                if ((! is_int($value) && ! (is_string($value) && ctype_digit($value))) || (int) $value < 1 || (int) $value > 30) {
                    $fail('Укажите целое число от 1 до 30 секунд.');
                }
            }])];
    }

    public function handle(array $config, ?WorkflowContext $context = null): array
    {
        $seconds = array_key_exists('seconds', $config) ? $config['seconds'] : 5;
        $seconds = $context ? $context->resolve($seconds) : $seconds;
        if ((! is_int($seconds) && ! (is_string($seconds) && ctype_digit($seconds))) || (int) $seconds < 1 || (int) $seconds > 30) {
            return ['success' => false, 'error' => 'Задержка должна быть целым числом от 1 до 30 секунд.'];
        }
        $preview = (bool) ($context?->getVariable('_dry_run') || $context?->getVariable('_test_mode'));
        if (! $preview) {
            Sleep::for((int) $seconds)->seconds();
        }
        $outputs = $context?->getStepOutputs() ?? [];

        return ['success' => true, 'delay_seconds' => (int) $seconds, 'simulated' => $preview,
            'output' => $outputs ? end($outputs) : ($context?->getTriggerData() ?? [])];
    }
}
