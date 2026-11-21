import test from 'node:test';
import assert from 'node:assert/strict';
import {crmTypedControl, crmLiteralValue, crmStoredValue} from '../../resources/js/workflow-crm-typed-value.mjs';

const fields = [
    {entity: 'leads', id: 12, type: 'multiselect', enums: '{"1":{"id":71,"value":"Один"},"2":{"id":72,"value":"Два"}}'},
    {entity: 'contacts', id: 12, type: 'checkbox'},
    {entity: 'leads', id: 13, type: 'date_time'},
    {entity: 'leads', id: 14, type: 'text'},
];

test('CRM mappings use entity metadata and enum labels compatible with the existing values payload', () => {
    const config = {fields:[{field:'12'}]};
    assert.deepEqual(crmTypedControl('amocrm_create_lead', 'fields.0.value', config, fields), {kind:'multiple', options:['Один', 'Два']});
    assert.deepEqual(crmTypedControl('amocrm_create_contact', 'fields.0.value', config, fields), {kind:'boolean'});
    assert.deepEqual(crmTypedControl('amocrm_update_fields', 'fields.0.value', {...config,target_entity:'contact'}, fields), {kind:'boolean'});
    assert.deepEqual(crmTypedControl('amocrm_create_lead', 'price', config, fields), {kind:'number'});
    assert.equal(crmTypedControl('amocrm_create_lead', 'fields.0.value', {fields:[{field:'14'}]}, fields), null);
    assert.equal(crmTypedControl('http_request', 'fields.0.value', config, fields), null);
});

test('system mappings reuse the relevant configured pipeline dictionary and fall back to IDs', () => {
    const config = {fields:[{field:'system:status_id'},{field:'system:pipeline_id',value:'121'}]};
    const dictionary = (name, pipeline) => name === 'status_id' && pipeline === '121' ? {42:'Новая'} : null;
    assert.deepEqual(crmTypedControl('amocrm_create_lead', 'fields.0.value', config, fields, dictionary), {kind:'dictionary',options:{42:'Новая'}});
    assert.deepEqual(crmTypedControl('amocrm_create_lead', 'fields.0.value', config, fields), {kind:'number'});
});

test('typed literals retain booleans, clearing, numeric precision, and deterministic UTC dates', () => {
    assert.equal(crmLiteralValue('boolean', false), 'no');
    assert.equal(crmStoredValue('boolean', 'no'), false);
    assert.equal(crmStoredValue('boolean', ''), null);
    assert.equal(crmStoredValue('number', '001.50'), '001.50');
    assert.deepEqual(crmStoredValue('multiple', ['Один', 'Два']), ['Один', 'Два']);
    assert.equal(crmStoredValue('date', ''), null);
    const timestamp = crmStoredValue('datetime-local', '2026-11-21T09:30');
    assert.equal(crmLiteralValue('datetime-local', timestamp), '2026-11-21T09:30');
    assert.equal(crmLiteralValue('date', timestamp), '2026-11-21');
});
