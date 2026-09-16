<?php

namespace App\Workflows\Actions;

use Filament\Forms\Components\Textarea;
use Leek\FilamentWorkflows\Concerns\WorkflowAction;
use Leek\FilamentWorkflows\Context\WorkflowContext;
use Symfony\Component\Process\Process;

class WorkflowJavascriptAction
{
    use WorkflowAction;

    public static function workflowType(): string { return 'workflow_javascript'; }
    public static function workflowName(): string { return 'JavaScript'; }
    public static function workflowDescription(): string { return 'Преобразовать данные кодом'; }
    public static function workflowIcon(): string { return 'heroicon-o-code-bracket'; }
    public static function workflowCategory(): string { return 'Управление потоком'; }
    public static function workflowDefaultConfig(): array { return ['javascript_code' => 'return $json;']; }
    public static function workflowConfigSchema(?string $modelClass = null): array
    {
        return [Textarea::make('javascript_code')->label('JavaScript')->hiddenLabel()->rows(16)->required()
            ->default('return $json;')->extraInputAttributes(['class' => 'workflow-json-input', 'spellcheck' => 'false', 'aria-label' => 'Код JavaScript'])];
    }

    public function handle(array $config, ?WorkflowContext $context = null): array
    {
        try {
            $outputs = $context?->getStepOutputs() ?? [];
            $nodes = array_map(fn ($output) => ['json' => $output], $outputs);
            $nodes['trigger'] = ['json' => $context?->getTriggerData() ?? []];
            $data = ['json' => $outputs ? end($outputs) : ($context?->getTriggerData() ?? []), 'nodes' => $nodes];
            $input = json_encode(['code' => $config['javascript_code'] ?? '', 'data' => $data], JSON_THROW_ON_ERROR);
            if (strlen($input) > 1024 * 1024) throw new \RuntimeException('Входные данные кода превышают 1 МБ.');
            $entry = base_path('bootstrap/workflow-runtime/run-javascript.mjs');
            if (!is_file($entry)) $entry = base_path('resources/workflows/run-javascript.mjs');
            $process = new Process([config('filament-workflows.javascript_binary', 'node'), '--max-old-space-size=64', $entry], base_path());
            $process->setInput($input)->setTimeout(5)->run();
            $result = json_decode($process->getOutput(), true);
            if (!$process->isSuccessful() || !is_array($result) || isset($result['error'])) {
                throw new \RuntimeException($result['error'] ?? 'Не удалось запустить JavaScript. Проверьте Node.js и установку npm-зависимостей.');
            }
            $output = $result['output'] ?? null;
            if (!is_array($output)) $output = ['value' => $output];
            elseif (array_is_list($output)) $output = ['items' => array_map(fn ($item) => is_array($item) && array_key_exists('json', $item) ? $item['json'] : $item, $output), 'count' => count($output)];
            if ($result['logs'] ?? []) $output['_console'] = $result['logs'];
            return ['success' => true, 'output' => $output];
        } catch (\Throwable $error) {
            return ['success' => false, 'error' => $error instanceof \Symfony\Component\Process\Exception\ProcessTimedOutException ? 'Превышен лимит выполнения JavaScript.' : $error->getMessage()];
        }
    }
}
