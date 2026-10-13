<?php

namespace App\Services\Workflows;

/** Presentation only: no changes to node configuration, geometry or execution. */
final class WorkflowNodeAppearance
{
    public static function action(string $type, array $config = [], string $fallback = ''): array
    {
        $controls = [
            'condition' => 'arrows-split', 'control-condition' => 'arrows-split',
            'workflow_loop' => 'arrows-clockwise', 'workflow_filter_list' => 'funnel',
            'workflow_delay' => 'clock',
        ];
        if (isset($controls[$type])) return self::look('control', $controls[$type]);
        if ($type === 'workflow_set_variable') return self::look('data', 'brackets-curly', 'equals');
        if ($type === 'workflow_javascript') return self::look('data', 'code');
        if (in_array($type, ['http_request', 'workflow_http_request'], true)) return self::look('http', 'globe');
        if ($type === 'telegram_send_message') return self::look('telegram', 'telegram-logo');
        if (!str_starts_with($type, 'amocrm_')) return self::look('neutral', 'gear');

        $special = [
            'amocrm_start_salesbot' => ['robot', 'play'],
            'amocrm_stop_salesbot' => ['robot', 'stop'],
            'amocrm_distribution_queue' => ['users', 'arrows-split'],
            'amocrm_manage_subscription' => ['bell', 'gear'],
            'amocrm_cancel_delayed_action' => ['clock', 'x'],
            'amocrm_set_global_variable' => ['brackets-curly', 'equals'],
            'amocrm_add_products' => ['package', 'plus'],
            'amocrm_remove_products' => ['package', 'minus'],
        ];
        if (isset($special[$type])) return self::look('crm', ...$special[$type]);

        $entityIcon = WorkflowAmoIcons::action($type, $config, $fallback);
        $entity = self::entityGlyph($entityIcon);
        $modifier = match (true) {
            $type === 'amocrm_unlink_entity' => 'link-break',
            $type === 'amocrm_link_entity' => 'link',
            $type === 'amocrm_change_lead_status' => 'arrow-right',
            str_contains($type, '_copy_') => 'copy',
            str_contains($type, '_create_'), $type === 'amocrm_add_note' => 'plus',
            str_contains($type, '_update_'), str_contains($type, '_normalize_'), $type === 'amocrm_change_tags' => 'pencil-simple',
            in_array($type, ['amocrm_read', 'amocrm_get_contact', 'amocrm_contact_leads', 'amocrm_query_leads', 'amocrm_find_entity'], true) => 'magnifying-glass',
            default => null,
        };
        return self::look('crm', $entity, $modifier);
    }

    public static function trigger(string $type, string $fallback = ''): array
    {
        $system = [
            'manual' => 'play', 'schedule' => 'clock', 'date-condition' => 'calendar',
            'generic-webhook' => 'webhooks-logo', 'workflow-completed' => 'check-circle',
            'amo-button' => 'cursor-click', 'amo-bulk' => 'stack',
        ];
        if (isset($system[$type])) return self::look('trigger', $system[$type]);
        $icon = WorkflowAmoIcons::trigger($type, $fallback);
        return self::look('trigger', self::entityGlyph($icon), 'lightning');
    }

    private static function entityGlyph(string $icon): string
    {
        return match ($icon) {
            'heroicon-o-user' => 'user',
            'amocrm-lead', 'heroicon-o-currency-dollar' => 'briefcase',
            'amocrm-customer', 'heroicon-o-users' => 'users',
            'heroicon-o-building-office-2' => 'buildings',
            'amocrm-task', 'heroicon-o-check-circle' => 'check-square',
            'heroicon-o-document-text' => 'note',
            'heroicon-o-tag' => 'tag',
            'amocrm-catalog' => 'package',
            'amocrm-imbox', 'heroicon-o-chat-bubble-left-right' => 'chat-circle',
            'heroicon-o-inbox-arrow-down' => 'tray',
            default => 'database',
        };
    }

    private static function look(string $family, string $glyph, ?string $modifier = null): array
    {
        return ['family' => $family, 'glyph' => $glyph, 'modifier' => $modifier];
    }
}
