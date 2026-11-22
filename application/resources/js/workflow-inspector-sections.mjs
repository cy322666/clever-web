// Presentation only: the complete schema and node config keep their original state paths.
const primaryPaths = {
    condition: ['logic', 'conditions'],
    'control-condition': ['logic', 'conditions'],
    workflow_delay: ['seconds'],
    workflow_loop: ['items'],
    workflow_filter_list: ['items', 'rules'],
    workflow_set_variable: ['assignments'],
    workflow_javascript: ['javascript_code'],
    http_request: ['method', 'url'],
    workflow_http_request: ['method', 'url'],
    telegram_send_message: ['credential_id', 'chat_id', 'text'],
    run_workflow: ['workflow_id'],
    workflow_call: ['workflow_id'],
    send_notification: ['title', 'body', 'recipient_type', 'recipient_user_id', 'recipient_role', 'recipient_field'],
    amocrm_create_lead: ['name', 'responsible_user_id', 'pipeline_id', 'status_id', 'contact_id'],
    amocrm_create_contact: ['name'],
    amocrm_create_company: ['name'],
    amocrm_copy_lead: ['target_entity_id'],
    amocrm_update_fields: ['target_entity', 'target_entity_id', 'fields'],
    amocrm_update_entity_fields: ['target_entity', 'target_entity_id', 'fields'],
    amocrm_update_lead_fields: ['target_entity_id', 'fields'],
    amocrm_update_contact_fields: ['target_entity_id', 'standard_fields.first_name', 'standard_fields.last_name', 'standard_fields.responsible_user_id', 'fields'],
    amocrm_update_company_fields: ['target_entity_id', 'fields'],
    amocrm_create_task: ['target_entity', 'target_entity_id', 'responsible_user_id', 'complete_till', 'text'],
    amocrm_add_note: ['target_entity', 'target_entity_id', 'is_system', 'text'],
    amocrm_change_tags: ['target_entity', 'target_entity_id', 'tags_to_add'],
    amocrm_change_lead_status: ['target_entity_id', 'pipeline_id', 'status_id'],
    amocrm_distribution_queue: ['target_entity_id', 'distribution_queue_uuid', 'pipeline_id', 'status_id'],
    amocrm_start_salesbot: ['bot_id', 'target_entity', 'target_entity_id'],
    amocrm_stop_salesbot: ['bot_id', 'target_entity', 'target_entity_id'],
    amocrm_manage_subscription: ['mode', 'event'],
    amocrm_update_task: ['target_entity', 'target_entity_id', 'text'],
    amocrm_cancel_delayed_action: ['workflow_id', 'action_id'],
    amocrm_normalize_contact_data: ['target_entity', 'target_entity_id'],
    amocrm_add_products: ['target_entity_id', 'catalog_id', 'products'],
    amocrm_remove_products: ['target_entity_id', 'catalog_id'],
    amocrm_find_entity: ['target_entity', 'conditions'],
    amocrm_link_entity: ['target_entity', 'target_entity_id', 'linked_entity', 'linked_entity_id'],
    amocrm_unlink_entity: ['target_entity', 'target_entity_id', 'linked_entity', 'linked_entity_id'],
    amocrm_get_contact: ['target_entity_id'],
    amocrm_read: ['operation_variant', 'operation', 'id', 'entity_id', 'pipeline_id', 'catalog_id', 'request_path', 'query', 'filters'],
    amocrm_query_leads: ['query', 'filters'],
    amocrm_contact_leads: ['source', 'lead_id', 'contact_id'],
    schedule: ['rules'],
    'generic-webhook': [],
};

const container = field => ['group', 'section'].includes(field.kind);
const leaves = fields => fields.flatMap(field => container(field) ? leaves(field.children || []) : [field]);

/** Keep dependencies, repeaters and paths intact while separating basic and extra controls. */
export function compactInspectorSections(fields = [], type = '') {
    const known = Object.hasOwn(primaryPaths, type);
    const wanted = new Set(known ? primaryPaths[type] : []);
    if (!known) {
        const editable = leaves(fields).filter(field => field.kind !== 'hidden' && field.kind !== 'view');
        for (const field of editable) {
            if (field.required === true && (field.default === undefined || field.default === null || field.default === '')) wanted.add(field.path);
        }
        if (!wanted.size && editable.length) wanted.add(editable[0].path);
    }
    const split = rows => {
        const primary = [], secondary = [];
        for (const field of rows) {
            if (field.kind === 'hidden') continue;
            if (container(field)) {
                const children = split(field.children || []);
                // One bottom button opens the extras, without another layer of collapsed sections.
                for (const [target, parts] of [[primary, children.primary], [secondary, children.secondary]]) {
                    if (parts.length) target.push({...field, collapsible: false, children: parts});
                }
            } else (field.kind === 'view' || wanted.has(field.path) ? primary : secondary).push(field);
        }
        return {primary, secondary};
    };
    return split(fields);
}

/** A repeater error belongs to its extra settings even when it targets a particular row. */
export function inspectorSectionsHaveErrors(fields, errors = {}) {
    const paths = leaves(fields).map(field => field.path).filter(Boolean);
    return Object.keys(errors).some(error => paths.some(path => error === path || error.startsWith(path + '.') || path.startsWith(error + '.')));
}

let nextSettingsId = 0;

/** The toggle stays below its content, like the existing full-width Add field button. */
export function createInspectorExtras(content, {open = false, onToggle = () => {}} = {}) {
    const element = document.createElement('div');
    element.className = 'workflow-inspector__advanced';
    content.classList.add('workflow-inspector__additional-fields');
    content.id = 'workflow-additional-settings-' + (++nextSettingsId);
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'fi-btn fi-size-sm workflow-inspector__more-settings';
    button.setAttribute('aria-controls', content.id);
    const setOpen = (expanded, notify = true) => {
        content.hidden = !expanded;
        button.setAttribute('aria-expanded', String(expanded));
        button.textContent = expanded ? 'Краткие настройки' : 'Все настройки';
        if (notify) onToggle(expanded);
    };
    setOpen(open, false);
    button.addEventListener('click', () => setOpen(content.hidden));
    element.append(content, button);
    return {element, button, content, setOpen};
}
