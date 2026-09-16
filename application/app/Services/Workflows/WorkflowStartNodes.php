<?php

namespace App\Services\Workflows;

use InvalidArgumentException;

/** Keeps the original primary trigger while adding independently connected entry points. */
final class WorkflowStartNodes
{
    public static function all(array $definition): array
    {
        $starts = empty($definition['trigger']) ? [] : ['trigger' => $definition['trigger']];
        $additional = $definition['additional_triggers'] ?? [];
        if (! is_array($additional) || count($additional) > 20) {
            throw new InvalidArgumentException('В сценарии доступно до 20 дополнительных запусков.');
        }
        foreach ($additional as $start) {
            if (! is_array($start) || ! preg_match('/^trigger:[a-zA-Z0-9_-]+$/D', $start['id'] ?? '') || isset($starts[$start['id']]) || ! is_string($start['type'] ?? null) || ! is_array($start['config'] ?? [])) {
                throw new InvalidArgumentException('Некорректная нода запуска.');
            }
            $starts[$start['id']] = $start;
        }

        return $starts;
    }

    public static function ofType(array $definition, string $type): array
    {
        return array_filter(self::all($definition), fn ($start) => ($start['type'] ?? '') === $type);
    }

    public static function selected(array $definition, array $input = []): string
    {
        $id = $input['_workflow_start_node_id'] ?? 'trigger';
        if (! is_string($id) || ! isset(self::all($definition)[$id])) {
            throw new InvalidArgumentException('Выбранный запуск отсутствует в сценарии.');
        }

        return $id;
    }

    public static function events(array $definition): array
    {
        return array_filter(array_map(fn ($start) => str_starts_with($start['type'] ?? '', 'amocrm-') && ($start['config']['source'] ?? '') === 'amocrm' ? ($start['config']['event'] ?? null) : null, self::all($definition)));
    }
}
