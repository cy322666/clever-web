<?php

namespace App\Services\Workflows;

/** The creation menu is deliberately smaller than the runtime registry. */
final class WorkflowNodeCatalog
{
    /** Menu labels use the selected entity context; node names keep their full meaning. */
    public static function actionLabel(string $type, string $fallback): string
    {
        return match ($type) {
            'amocrm_create_lead', 'amocrm_create_contact', 'amocrm_create_company', 'amocrm_create_task' => 'Создать',
            'amocrm_update_lead_fields', 'amocrm_update_contact_fields', 'amocrm_update_company_fields' => 'Обновить',
            'amocrm_change_lead_status' => 'Изменить этап',
            default => $fallback,
        };
    }

    public static function triggerTypes(): array
    {
        return [
            'manual', 'amo-button', 'amo-bulk', 'digital-pipeline', 'schedule', 'generic-webhook',
            'amocrm-add-lead', 'amocrm-update-lead', 'amocrm-status-lead',
            'amocrm-add-contact', 'amocrm-update-contact',
            'amocrm-add-company', 'amocrm-update-company',
            'amocrm-add-task', 'amocrm-update-task',
        ];
    }

    public static function actionTypes(): array
    {
        return [
            'control-condition', 'workflow_javascript', 'workflow_delay',
            'workflow_loop', 'workflow_set_variable', 'http_request', 'telegram_send_message',
            'amocrm_create_lead', 'amocrm_update_lead_fields', 'amocrm_change_lead_status',
            'amocrm_create_contact', 'amocrm_update_contact_fields',
            'amocrm_create_company', 'amocrm_update_company_fields',
            'amocrm_create_task',
            'amocrm_add_note', 'amocrm_change_tags', 'amocrm_link_entity',
        ];
    }

    public static function includesTrigger(string $type): bool
    {
        return in_array($type, self::triggerTypes(), true);
    }

    public static function includesAction(string $type): bool
    {
        return in_array($type, self::actionTypes(), true);
    }

    /** One read node per basic entity, with list/ID modes inside its settings. */
    public static function readOperations(): array
    {
        return array_intersect_key(WorkflowAmoReadCatalog::availableOperations(), array_flip([
            'leads.list', 'contacts.list', 'companies.list', 'tasks.list',
        ]));
    }
}
