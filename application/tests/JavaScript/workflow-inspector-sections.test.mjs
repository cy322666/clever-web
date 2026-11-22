import test from 'node:test';
import assert from 'node:assert/strict';
import {compactInspectorSections, inspectorSectionsHaveErrors} from '../../resources/js/workflow-inspector-sections.mjs';
import {nodeFields} from '../../resources/js/workflow-browser-fields.mjs';

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

test('the portable editor uses the same minimal field selection without changing saved optional values', () => {
    const node={type:'amocrm_create_lead',config:{name:'Заявка',price:'{{ $json.total }}',tags:'VIP',custom_legacy:{keep:true}}};
    const before=JSON.stringify(node);
    const fields=nodeFields(node,{},{});
    const result=compactInspectorSections(fields,node.type);
    assert.deepEqual(paths(result.primary),['name','responsible_user_id','pipeline_id','status_id','contact_id']);
    assert.ok(paths(result.secondary).includes('price'));
    assert.ok(paths(result.secondary).includes('fields'));
    assert.equal(JSON.stringify(node),before);
});


test('portable contact update exposes the same separate names without changing stored full names',()=>{
    const node={type:'amocrm_update_contact_fields',config:{standard_fields:{name:'Legacy Full Name',first_name:'First',last_name:'Last'}}};
    const before=JSON.stringify(node);
    const {primary,secondary}=compactInspectorSections(nodeFields(node,{users:{9:'Manager'}}),node.type);
    assert.ok(paths(primary).includes('standard_fields.first_name'));
    assert.ok(paths(primary).includes('standard_fields.last_name'));
    assert.ok(paths(primary).includes('standard_fields.responsible_user_id'));
    assert.ok(paths(secondary).includes('standard_fields.name'));
    assert.equal(JSON.stringify(node),before);
});

test('read forms show filters and parameters together without a mode selector',()=>{
    const refs={query:{'contacts.list':{sorts:{id:'ID'}}}};
    for(const mode of ['fields','builder']) {
        const node={type:'amocrm_read',config:{operation:'contacts.list',body_mode:mode,parameters:[{name:'with',value:'leads'}]}};
        const before=JSON.stringify(node),fields=nodeFields(node,refs,{});
        assert.ok(fields.some(field=>field.path==='filters'));
        assert.ok(fields.some(field=>field.path==='parameters'));
        assert.ok(fields.some(field=>field.path==='query'));
        assert.equal(fields.some(field=>field.path==='body_mode'),false);
        assert.equal(JSON.stringify(node),before);
    }
    const one=nodeFields({type:'amocrm_read',config:{operation:'contacts.one',body_mode:'fields'}},refs,{});
    assert.equal(one.some(field=>field.path==='filters'),false);
    assert.ok(one.some(field=>field.path==='parameters'));
});


test('entity creation keeps linking optional and outside brief settings',()=>{
    for(const type of ['amocrm_create_contact','amocrm_create_company']) {
        const node={type,config:{name:'Standalone',entity_source:'manual',target_entity:null,target_entity_id:null,body_mode:'fields'}};
        let fields=nodeFields(node,{},{}),sections=compactInspectorSections(fields,type);
        assert.deepEqual(paths(sections.primary),['name']);
        assert.ok(paths(sections.secondary).includes('target_entity'));
        assert.equal(fields.find(field=>field.path==='target_entity').placeholder,'Не привязывать');
        assert.equal(fields.find(field=>field.path==='target_entity_id').visible(),false);
        node.config.target_entity='lead';node.config.target_entity_id='{{ $json.id }}';
        fields=nodeFields(node,{},{});
        assert.equal(fields.find(field=>field.path==='target_entity_id').visible(),true);
        assert.equal(node.config.target_entity_id,'{{ $json.id }}');
    }
});


test('additional fields exclude duplicate controls while universal updates keep system fields',()=>{
    const refs={fields:[{id:101,name:'Custom lead',entity:'leads'},{id:102,name:'Custom contact',entity:'contacts'},{id:103,name:'Custom company',entity:'companies'}]};
    for(const [type,entity,id] of [
        ['amocrm_create_lead','lead',101],['amocrm_create_contact','contact',102],['amocrm_create_company','company',103],
        ['amocrm_update_lead_fields','lead',101],['amocrm_update_contact_fields','contact',102],['amocrm_update_company_fields','company',103],
    ]) {
        const node={type,config:{target_entity:entity,body_mode:'fields',fields:[{field:'system:responsible_user_id',value:9}]}};
        const before=JSON.stringify(node),mappings=nodeFields(node,refs).find(field=>field.path==='fields'),options=mappings.fields[0].options;
        assert.ok(Object.hasOwn(options,id),type);
        assert.equal(Object.hasOwn(options,'system:responsible_user_id'),false,type);
        assert.equal(Object.hasOwn(options,'system:name'),false,type);
        if(['amocrm_update_contact_fields','amocrm_update_company_fields'].includes(type)) assert.equal(Object.keys(options).some(key=>key.startsWith('system:')),false,type);
        if(type==='amocrm_update_lead_fields') assert.ok(Object.hasOwn(options,'system:closed_at'),'Fields without a separate control remain available');
        assert.equal(JSON.stringify(node),before,'Saved field values must remain intact');
    }
    const general=nodeFields({type:'amocrm_update_entity_fields',config:{target_entity:'lead',body_mode:'fields'}},refs).find(field=>field.path==='fields');
    assert.ok(Object.hasOwn(general.fields[0].options,'system:responsible_user_id'));
    const linkedCompany=nodeFields({type:'amocrm_create_company',config:{target_entity:'lead',body_mode:'fields'}},refs).find(field=>field.path==='fields');
    assert.ok(Object.hasOwn(linkedCompany.fields[0].options,103));
    assert.equal(Object.hasOwn(linkedCompany.fields[0].options,101),false);
});


test('query filter dictionaries offer users, pipelines and only the chosen pipeline stages',()=>{
    const refs={users:{9:'Яна'},pipelines:{10:'Основная',20:'Повторная'},statuses:[
        {id:101,pipeline_id:10,name:'Новый'},{id:201,pipeline_id:20,name:'В работе'}
    ],query:{'leads.list':{fields:{pipeline_id:{label:'Воронка'},statuses:{label:'Этап'},responsible_user_id:{label:'Ответственный'},created_by:{label:'Кем создано'},updated_by:{label:'Кем изменено'}}}}};
    const config={operation:'leads.list',body_mode:'builder',filters:[{field:'pipeline_id',value:'{{ $json.pipeline_id }}'}]};
    const before=JSON.stringify(config);
    const filters=nodeFields({type:'amocrm_read',config},refs).find(field=>field.path==='filters');
    const choices=filters.fields.find(field=>field.path==='value').options;
    assert.deepEqual(choices({field:'pipeline_id'}),{10:'Основная',20:'Повторная'});
    for(const field of ['responsible_user_id','created_by','updated_by']) assert.deepEqual(choices({field}),{9:'Яна'});
    assert.deepEqual(choices({field:'statuses',pipeline_id:'10'}),{101:'Новый'});
    assert.deepEqual(choices({field:'statuses',pipeline_id:'20'}),{201:'В работе'});
    assert.deepEqual(choices({field:'statuses',pipeline_id:'{{ $json.pipeline_id }}'}),{});
    assert.deepEqual(choices({field:'statuses',pipeline_id:''}),{});
    assert.deepEqual(choices({field:'id'}),{});
    assert.equal(JSON.stringify(config),before,'Selecting a dictionary must not rewrite saved expressions on opening');
});
