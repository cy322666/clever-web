<?php

declare(strict_types=1);

namespace App\Services\Workflows;

final class WorkflowDocumentationLinks
{
    public const BASE_URL = 'https://clevercrm.pro/docs/flows';

    /** @var array<string, string> */
    private const TYPE_PAGES = [
        'amocrm_create_lead' => 'node-amocrm-create-lead',
        'amocrm_create_contact' => 'node-amocrm-create-contact',
        'amocrm_create_company' => 'node-amocrm-create-company',
        'amocrm_update_lead_fields' => 'node-amocrm-update-lead-fields',
        'amocrm_update_contact_fields' => 'node-amocrm-update-contact-fields',
        'amocrm_update_company_fields' => 'node-amocrm-update-company-fields',
        'amocrm_create_task' => 'node-amocrm-create-task',
        'amocrm_add_note' => 'node-amocrm-add-note',
        'amocrm_change_tags' => 'node-amocrm-change-tags',
        'amocrm_change_lead_status' => 'node-amocrm-change-lead-status',
        'amocrm_start_salesbot' => 'node-amocrm-start-salesbot',
        'amocrm_link_entity' => 'node-amocrm-link-entity',
        'amocrm_query_leads' => 'node-amocrm-query-leads',
        'amocrm_contact_leads' => 'node-amocrm-contact-leads',
        'amocrm_get_contact' => 'node-amocrm-get-contact',
        'control-condition' => 'node-control-condition',
        'workflow_filter_list' => 'node-workflow-filter-list',
        'workflow_loop' => 'node-workflow-loop',
        'workflow_set_variable' => 'node-workflow-set-variable',
        'workflow_delay' => 'node-workflow-delay',
        'http_request' => 'node-http-request',
        'workflow_javascript' => 'node-workflow-javascript',
        'telegram_send_message' => 'node-telegram-send-message',
        'manual' => 'node-manual',
        'amo-button' => 'node-amo-button',
        'amo-bulk' => 'node-amo-bulk',
        'digital-pipeline' => 'node-digital-pipeline',
        'schedule' => 'node-schedule',
        'generic-webhook' => 'node-generic-webhook',
        'amocrm-responsible-lead' => 'node-amocrm-responsible-lead',
        'amocrm-responsible-contact' => 'node-amocrm-responsible-contact',
        'amocrm-responsible-company' => 'node-amocrm-responsible-company',
        'amocrm-responsible-customer' => 'node-amocrm-responsible-customer',
        'amocrm-responsible-task' => 'node-amocrm-responsible-task',
        'amocrm-restore-lead' => 'node-amocrm-restore-lead',
        'amocrm-restore-contact' => 'node-amocrm-restore-contact',
        'amocrm-restore-company' => 'node-amocrm-restore-company',
        'amocrm-add-lead' => 'node-amocrm-add-lead',
        'amocrm-add-contact' => 'node-amocrm-add-contact',
        'amocrm-add-company' => 'node-amocrm-add-company',
        'amocrm-add-customer' => 'node-amocrm-add-customer',
        'amocrm-add-talk' => 'node-amocrm-add-talk',
        'amocrm-add-task' => 'node-amocrm-add-task',
        'amocrm-update-lead' => 'node-amocrm-update-lead',
        'amocrm-update-contact' => 'node-amocrm-update-contact',
        'amocrm-update-company' => 'node-amocrm-update-company',
        'amocrm-update-customer' => 'node-amocrm-update-customer',
        'amocrm-update-talk' => 'node-amocrm-update-talk',
        'amocrm-update-task' => 'node-amocrm-update-task',
        'amocrm-delete-lead' => 'node-amocrm-delete-lead',
        'amocrm-delete-contact' => 'node-amocrm-delete-contact',
        'amocrm-delete-company' => 'node-amocrm-delete-company',
        'amocrm-delete-customer' => 'node-amocrm-delete-customer',
        'amocrm-delete-task' => 'node-amocrm-delete-task',
        'amocrm-status-lead' => 'node-amocrm-status-lead',
        'amocrm-note-lead' => 'node-amocrm-note-lead',
        'amocrm-note-contact' => 'node-amocrm-note-contact',
        'amocrm-note-company' => 'node-amocrm-note-company',
        'amocrm-note-customer' => 'node-amocrm-note-customer',
        'amocrm-add-message' => 'node-amocrm-add-message',
        'amocrm-add-outgoing-message' => 'node-amocrm-add-outgoing-message',
        'amocrm-add-unsorted' => 'node-amocrm-add-unsorted',
        'amocrm-update-unsorted' => 'node-amocrm-update-unsorted',
        'amocrm-delete-unsorted' => 'node-amocrm-delete-unsorted',
        'amocrm-add-chat-template-review' => 'node-amocrm-add-chat-template-review',
    ];

    /** @var array<string, string> */
    private const READ_PAGES = [
        'leads.list' => 'node-amocrm-read-leads-list',
        'leads.links' => 'node-amocrm-read-leads-links',
        'leads.notes.all' => 'node-amocrm-read-leads-notes-all',
        'leads.tags' => 'node-amocrm-read-leads-tags',
        'leads.subscriptions' => 'node-amocrm-read-leads-subscriptions',
        'leads.custom_fields' => 'node-amocrm-read-leads-custom-fields',
        'contacts.list' => 'node-amocrm-read-contacts-list',
        'contacts.links' => 'node-amocrm-read-contacts-links',
        'contacts.notes.all' => 'node-amocrm-read-contacts-notes-all',
        'contacts.tags' => 'node-amocrm-read-contacts-tags',
        'contacts.custom_fields' => 'node-amocrm-read-contacts-custom-fields',
        'companies.list' => 'node-amocrm-read-companies-list',
        'companies.links' => 'node-amocrm-read-companies-links',
        'companies.notes.all' => 'node-amocrm-read-companies-notes-all',
        'companies.tags' => 'node-amocrm-read-companies-tags',
        'companies.custom_fields' => 'node-amocrm-read-companies-custom-fields',
        'customers.list' => 'node-amocrm-read-customers-list',
        'customers.links' => 'node-amocrm-read-customers-links',
        'customers.notes.all' => 'node-amocrm-read-customers-notes-all',
        'customers.tags' => 'node-amocrm-read-customers-tags',
        'customers.subscriptions' => 'node-amocrm-read-customers-subscriptions',
        'customers.custom_fields' => 'node-amocrm-read-customers-custom-fields',
        'tasks.list' => 'node-amocrm-read-tasks-list',
        'catalogs.list' => 'node-amocrm-read-catalogs-list',
        'elements.list' => 'node-amocrm-read-elements-list',
        'catalog_fields.list' => 'node-amocrm-read-catalog-fields-list',
        'transactions.list' => 'node-amocrm-read-transactions-list',
        'customer_statuses.list' => 'node-amocrm-read-customer-statuses-list',
        'segments.list' => 'node-amocrm-read-segments-list',
        'segment_fields.list' => 'node-amocrm-read-segment-fields-list',
        'events.list' => 'node-amocrm-read-events-list',
        'event_types' => 'node-amocrm-read-event-types',
        'custom' => 'node-amocrm-read-custom',
    ];

    /** @return array<string, string> */
    public static function typePages(): array
    {
        return self::TYPE_PAGES;
    }

    /** @return array<string, string> */
    public static function readPages(): array
    {
        $pages = [];

        foreach (WorkflowAmoReadCatalog::availableOperations() as $family => $item) {
            if (! isset(self::READ_PAGES[$family])) {
                continue;
            }

            foreach (array_keys($item['variants'] ?? []) as $operation) {
                $pages[$operation] = self::READ_PAGES[$family];
            }
        }

        return $pages;
    }

    public static function forNode(string $type, array $config = []): string
    {
        if ($type === 'amocrm_read') {
            $operation = $config['operation'] ?? '';
            $page = is_string($operation) ? (self::readPages()[$operation] ?? 'query') : 'query';
        } else {
            $page = self::TYPE_PAGES[$type] ?? 'blocks';
        }

        return self::BASE_URL.'/'.$page;
    }
}
