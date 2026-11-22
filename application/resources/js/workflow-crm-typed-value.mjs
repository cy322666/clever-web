const mappingEntities = {
    amocrm_create_lead: 'leads',
    amocrm_create_contact: 'contacts',
    amocrm_create_company: 'companies',
    amocrm_update_lead_fields: 'leads',
    amocrm_update_contact_fields: 'contacts',
    amocrm_update_company_fields: 'companies',
};

const entities = {lead: 'leads', contact: 'contacts', company: 'companies', customer: 'customers'};
const numbers = new Set(['price', 'next_price', 'responsible_user_id', 'pipeline_id', 'status_id', 'loss_reason_id']);
const singleEnums = new Set(['select', 'radiobutton', 'category']);

function enumOptions(enums) {
    if (typeof enums === 'string') {
        try { enums = JSON.parse(enums); } catch { return []; }
    }
    // The executor wraps each literal as {value}; amoCRM accepts enum labels in value,
    // whereas numeric enum IDs need a distinct enum_id field.
    return [...new Set(Object.values(enums || {}).map(item => item?.value ?? item?.name)
        .filter(label => typeof label === 'string' && label !== ''))];
}

export function crmTypedControl(type, path, config, fields = [], dictionary = () => null) {
    const mapping = /^fields\.(\d+)\.value$/.exec(path);
    let fieldName;
    let fieldType;
    let options = [];
    if (mapping && (Object.hasOwn(mappingEntities, type) || type === 'amocrm_update_fields')) {
        const selection = config.fields?.[mapping[1]]?.field;
        if (selection == null || selection === '') return null;
        if (String(selection).startsWith('system:')) {
            fieldName = String(selection).slice(7);
            const pipeline = config.pipeline_id || config.standard_fields?.pipeline_id
                || config.fields.find(row => row.field === 'system:pipeline_id')?.value;
            const choices = dictionary(fieldName, pipeline);
            if (fieldName === 'responsible_user_id' || (choices && Object.keys(choices).length)) return {kind: 'dictionary', options: choices || {}};
        } else {
            const entity = mappingEntities[type] || (entities[config.target_entity] || config.target_entity || 'leads');
            const field = fields.find(row => row.entity === entity && String(row.id) === String(selection));
            fieldType = field?.type;
            options = enumOptions(field?.enums);
        }
    } else if (Object.hasOwn(mappingEntities, type) && /^(?:standard_fields\.)?(?:price|next_price|closed_at|contact_id|company_id)$/.test(path)) {
        fieldName = path.split('.').at(-1);
    } else {
        return null;
    }

    if (singleEnums.has(fieldType) || fieldType === 'multiselect') {
        return options.length ? {kind: fieldType === 'multiselect' ? 'multiple' : 'choice', options} : null;
    }
    if (fieldType === 'checkbox') return {kind: 'boolean'};
    if (fieldType === 'textarea') return {kind: 'textarea'};
    if (fieldType === 'date_time') return {kind: 'datetime-local'};
    if (['date', 'birthday'].includes(fieldType) || fieldName === 'closed_at') return {kind: 'date'};
    if (['numeric', 'price', 'monetary'].includes(fieldType) || numbers.has(fieldName) || ['contact_id', 'company_id'].includes(fieldName)) return {kind: 'number'};
    if (fieldType === 'url') return {kind: 'url'};
    return null;
}

export function crmLiteralValue(kind, value) {
    if (kind === 'boolean') {
        if ([true, 1, '1', 'true'].includes(value)) return 'yes';
        if ([false, 0, '0', 'false'].includes(value)) return 'no';
        return '';
    }
    if (kind === 'multiple') return Array.isArray(value) ? value.map(item => String(item?.value ?? item)) : value == null || value === '' ? [] : [String(value)];
    if (kind === 'date' || kind === 'datetime-local') {
        if (value == null || value === '') return '';
        const date = /^-?\d+$/.test(String(value)) ? new Date(Number(value) * 1000) : new Date(value);
        if (Number.isNaN(date.getTime())) return '';
        return date.toISOString().slice(0, kind === 'date' ? 10 : 16);
    }
    return value ?? '';
}

export function crmStoredValue(kind, value) {
    if (kind === 'boolean') return value === 'yes' ? true : value === 'no' ? false : null;
    if (kind === 'date' || kind === 'datetime-local') {
        if (!value) return null;
        const timestamp = Date.parse(kind === 'date' ? value + 'T00:00:00Z' : value + 'Z');
        return Number.isFinite(timestamp) ? timestamp / 1000 : null;
    }
    return value;
}
