<?php

namespace App\Services\Workflows;

use App\Workflows\Actions\WorkflowTriggerConditionVariableCatalog;
use Leek\FilamentWorkflows\Services\WorkflowVariableService as BaseWorkflowVariableService;

class WorkflowVariableService extends BaseWorkflowVariableService
{
    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model>|null $modelClass
     * @param array<int, array<string, mixed>> $previousActions
     * @return array<array{path: string, label: string, description?: string, category: string}>
     */
    public function getAvailableVariables(?string $modelClass = null, array $previousActions = []): array
    {
        return $this->uniqueByPath([
            ...$this->getAmoCrmTriggerVariables(),
            ...$this->withoutTriggerPrefix(parent::getAvailableVariables($modelClass, $previousActions)),
        ]);
    }

    /**
     * @return array<array{path: string, label: string, description?: string, category: string}>
     */
    private function getAmoCrmTriggerVariables(): array
    {
        $variables = [];

        foreach (WorkflowTriggerConditionVariableCatalog::groupedOptions(false) as $category => $options) {
            foreach ($options as $placeholder => $label) {
                $path = $this->placeholderToPath((string)$placeholder);

                if ($path === '') {
                    continue;
                }

                $variables[] = [
                    'path' => $path,
                    'label' => (string)$label,
                    'description' => (string)$category === 'Вебхук'
                        ? 'Данные входящего вебхука'
                        : 'Данные входящего события',
                    'category' => (string)$category,
                ];
            }
        }

        return $variables;
    }

    private function placeholderToPath(string $placeholder): string
    {
        $placeholder = trim($placeholder);

        if (str_starts_with($placeholder, '{{') && str_ends_with($placeholder, '}}')) {
            return trim(substr($placeholder, 2, -2));
        }

        return $placeholder;
    }

    /**
     * @param array<int, array{path: string, label: string, description?: string, category: string}> $variables
     * @return array<array{path: string, label: string, description?: string, category: string}>
     */
    private function withoutTriggerPrefix(array $variables): array
    {
        return array_map(static function (array $variable): array {
            $path = (string)($variable['path'] ?? '');

            if (str_starts_with($path, 'trigger.')) {
                $variable['path'] = substr($path, 8);
            }

            return $variable;
        }, $variables);
    }

    /**
     * @param array<int, array{path: string, label: string, description?: string, category: string}> $variables
     * @return array<array{path: string, label: string, description?: string, category: string}>
     */
    private function uniqueByPath(array $variables): array
    {
        $result = [];

        foreach ($variables as $variable) {
            $path = $variable['path'] ?? '';

            if ($path === '' || isset($result[$path])) {
                continue;
            }

            $result[$path] = $variable;
        }

        return array_values($result);
    }
}
