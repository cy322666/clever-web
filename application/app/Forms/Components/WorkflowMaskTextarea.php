<?php

namespace App\Forms\Components;

use App\Workflows\Actions\WorkflowTriggerConditionVariableCatalog;
use Leek\FilamentWorkflows\Forms\Components\VariableTextarea;

class WorkflowMaskTextarea extends VariableTextarea
{
    /**
     * @return array<string, array<array{path: string, label: string, description?: string}>>
     */
    public function getGroupedVariables(): array
    {
        return collect(WorkflowTriggerConditionVariableCatalog::groupedOptions(false))
            ->mapWithKeys(static function (array $items, string $group): array {
                $variables = collect($items)
                    ->map(static fn(string $label, string $mask): array => [
                        'path' => trim($mask, '{} '),
                        'label' => $label,
                    ])
                    ->values()
                    ->all();

                return [$group => $variables];
            })
            ->all();
    }
}
