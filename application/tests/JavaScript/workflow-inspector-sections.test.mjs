import test from 'node:test';
import assert from 'node:assert/strict';
import {compactInspectorSections, inspectorSectionsHaveErrors} from '../../resources/js/workflow-inspector-sections.mjs';


const input = (path, extra = {}) => ({kind:'value', path, ...extra});
const paths = fields => fields.flatMap(field => ['group','section'].includes(field.kind) ? paths(field.children || []) : [field.path]);

test('basic settings stay minimal across creation, requests, loops and schedules', () => {
    for (const [type, fields, expected] of [
        ['amocrm_create_lead', ['name','responsible_user_id','price','pipeline_id','status_id','contact_id','tags'], ['name','responsible_user_id','pipeline_id','status_id','contact_id']],
        ['amocrm_create_contact', ['name','target_entity','target_entity_id','responsible_user_id','tags','fields'], ['name']],
        ['amocrm_create_company', ['name','target_entity','target_entity_id','responsible_user_id','tags','fields'], ['name']],
        ['amocrm_create_task', ['target_entity','target_entity_id','responsible_user_id','task_type_id','complete_till','text'], ['target_entity','target_entity_id','responsible_user_id','complete_till','text']],
        ['amocrm_add_note', ['target_entity','target_entity_id','is_system','text'], ['target_entity','target_entity_id','is_system','text']],
        ['http_request', ['method','url','headers','body_format','body','form_fields','timeout','allowed_status_codes'], ['method','url']],
        ['workflow_loop', ['items','mode','limit'], ['items']],
        ['telegram_send_message', ['credential_id','chat_id','text','parse_mode','continue_on_error'], ['credential_id','chat_id','text']],
        ['schedule', ['timezone','rules'], ['rules']],
    ]) {
        const schema=fields.map(path=>input(path));
        const {primary,secondary}=compactInspectorSections(schema,type);
        assert.deepEqual(paths(primary),expected,type);
        assert.deepEqual([...paths(primary),...paths(secondary)].sort(),fields.slice().sort(),type);
    }
});

test('optional groups retain dependencies and repeater rows without mutating the source schema', () => {
    const visible={dependency:'mode',cases:[{value:'limit',result:true}],fallback:false};
    const schema=[input('items'), {kind:'section',path:'',collapsible:true,collapsed:true,visible,attributes:{class:'existing'},children:[input('mode'),input('limit',{required:true}),{kind:'repeater',path:'fields',children:[input('fields.*.field',{required:true}),input('fields.*.value')]}]}];
    const before=JSON.stringify(schema);
    const {primary,secondary}=compactInspectorSections(schema,'workflow_loop');
    assert.deepEqual(paths(primary),['items']);
    assert.equal(secondary[0].visible,visible);
    assert.equal(secondary[0].collapsible,false);
    assert.deepEqual(secondary[0].children,schema[1].children);
    assert.equal(JSON.stringify(schema),before);
});

test('contact updates show separate first and last names with responsible in brief settings', () => {
    const mappings={kind:'repeater',path:'fields',children:[input('fields.*.field'),input('fields.*.value')]};
    const schema=[input('target_entity_id'),input('standard_fields.name'),input('standard_fields.first_name'),input('standard_fields.last_name'),input('standard_fields.responsible_user_id'),mappings];
    const result=compactInspectorSections(schema,'amocrm_update_contact_fields');
    assert.deepEqual(result.primary,[schema[0],schema[2],schema[3],schema[4],mappings]);
    assert.deepEqual(paths(result.secondary),['standard_fields.name']);
});

test('extra-setting validation errors include nested system fields and particular repeater rows', () => {
    const fields=[input('price'),input('standard_fields.name'),{kind:'repeater',path:'fields',children:[]}];
    assert.equal(inspectorSectionsHaveErrors(fields,{'fields.2.value':['Required']}),true);
    assert.equal(inspectorSectionsHaveErrors(fields,{'standard_fields.name':['Invalid']}),true);
    assert.equal(inspectorSectionsHaveErrors(fields,{'standard_fields':['Invalid']}),true);
    assert.equal(inspectorSectionsHaveErrors(fields,{'name':['Required']}),false);
});

test('simple one-field nodes have no redundant additional-settings section', () => {
    for (const [type,path] of [['workflow_delay','seconds'],['workflow_javascript','javascript_code'],['workflow_set_variable','assignments'],['amocrm_get_contact','target_entity_id']]) {
        const result=compactInspectorSections([input(path),{kind:'hidden',path:'internal'}],type);
        assert.deepEqual(paths(result.primary),[path]);
        assert.deepEqual(result.secondary,[]);
    }
});

test('custom nodes keep required inputs visible and their remaining settings accessible', () => {
    const schema=[input('optional'),input('required',{required:true}),input('with_default',{required:true,default:10})];
    for (const type of ['custom_node', 'constructor', '__proto__']) {
        const {primary,secondary}=compactInspectorSections(schema,type);
        assert.deepEqual(paths(primary),['required']);
        assert.deepEqual(paths(secondary),['optional','with_default']);
    }
});
















