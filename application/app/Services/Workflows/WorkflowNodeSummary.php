<?php

namespace App\Services\Workflows;

use App\Workflows\Actions\WorkflowConditionPreview;
use Illuminate\Support\Str;

final class WorkflowNodeSummary
{
    public static function for(array $action): ?string
    {
        $config = $action['config'] ?? [];
        $text = match ($action['type'] ?? '') {
            'condition', 'control-condition' => self::condition($config),
            'amocrm_create_task' => filled($config['text'] ?? null) ? 'Задача: '.$config['text'] : null,
            'amocrm_add_note' => filled($config['text'] ?? null) ? 'Примечание: '.$config['text'] : null,
            'workflow_delay' => isset($config['seconds']) ? 'Подождать '.$config['seconds'].' сек.' : null,
            'amocrm_create_company', 'amocrm_create_contact', 'amocrm_create_lead' => $config['name'] ?? null,
            default => null,
        };

        return is_scalar($text) && filled($text) ? Str::limit(preg_replace('/\s+/u', ' ', strip_tags((string) $text)), 90) : null;
    }

    private static function condition(array $config): ?string
    {
        $row = WorkflowConditionPreview::rows($config, 1)[0] ?? null;
        if (! $row) {
            return null;
        }

        return implode(' ', array_filter([$row['left'], $row['operator'], $row['right']], fn ($v) => $v !== null))
            .(count($config['conditions'] ?? []) > 1 ? ' · ещё '.(count($config['conditions']) - 1) : '');
    }
}
