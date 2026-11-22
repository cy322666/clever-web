import {compactInspectorSections} from '../../resources/js/workflow-inspector-sections.mjs';
import test from 'node:test';
import assert from 'node:assert/strict';
import {WorkflowLocalInspector, inspectorRule, inspectorSource, withKnownInspectorPaths} from '../../resources/js/workflow-local-inspector.mjs';
import {clone,setPath} from '../../resources/js/workflow-browser-document.mjs';

function fixture() {
    const writes=[];
    const definition={trigger:{type:'manual',config:[]},actions:[
        {id:'http',name:'Заказ',type:'http_request',config:{url:'https://example.test',headers:'{"Authorization":"keep-secret"}',vendor:{keep:true},body_format:'json',form_fields:[{name:'a',value:'b'}]}},
        {id:'read',name:'Сделки',type:'amocrm_read',config:{operation:'leads.one',id:'{{ $json.id }}',parameters:[],body_mode:'fields'}},
        {id:'end',name:'Условие',type:'control-condition',config:{conditions:[{left:'{{ $("Заказ").body }}',operator:'equals',right:'x'}]}},
    ],connections:[{sourceId:'trigger',targetId:'action:http'},{sourceId:'action:http',targetId:'action:read'},{sourceId:'action:read',targetId:'action:end'}],vendor:'preserve'};
    const wire={workflowActions:clone(definition.actions),definition:clone(definition),trigger:clone(definition.trigger),workflowLocalInspectorDirty:{},
        $set(path,value,live){assert.equal(live,false,'All editing writes must be deferred');setPath(this,path,value);writes.push(path);},
        openWorkflowInspector(){assert.fail('No server form call');},callMountedAction(){assert.fail('No backend on Apply');},
        debugState:{results:[]},nodeRunResults:{},
    };
    const field=(path,kind='value',extra={})=>({path,name:path,kind,children:[],...extra});
    const boot={schemas:{'action:http_request':[field('url'),field('headers'),field('body_format'),field('body'),field('form_fields','repeater',{children:[field('form_fields.*.name'),field('form_fields.*.value')]})],
        'action:amocrm_read':[field('operation_variant','select'),field('operation','hidden'),field('parameters','repeater',{children:[field('parameters.*.name'),field('parameters.*.value')]})],
        'action:control-condition':[field('conditions','repeater',{children:[field('conditions.*.left'),field('conditions.*.operator'),field('conditions.*.right')]})]},
        queryOperations:['leads.list'],withOptions:{'leads.one':{contacts:'Контакты',tags:'Теги'}},sources:[],conditionFields:[]};
    return {wire,writes,local:new WorkflowLocalInspector(wire,boot)};
}

test('manual start opens node actions without a settings schema or deferred writes',()=>{
    const {wire,writes,local}=fixture(),before=clone(wire.definition);
    local.begin('trigger');
    assert.equal(local.id,'trigger');
    assert.deepEqual(local.schema,[]);
    assert.deepEqual(wire.definition,before);
    assert.deepEqual(writes,[]);
});

test('opening and switching node settings does not write or request anything',()=>{
    const {wire,writes,local}=fixture(),before=clone(wire.definition);
    local.begin('action:http');local.begin('action:read');local.begin('action:http');
    assert.deepEqual(wire.definition,before);
    assert.deepEqual(writes,[]);
});

test('changes survive node switching and preserve credentials, unknown keys and expressions',async()=>{
    const {wire,local}=fixture();
    local.begin('action:http');local.set('url','{{ $("Сделки").url }}');await Promise.resolve();
    local.begin('action:read');local.set('id','{{ $json.order_id }}');local.flush();
    local.begin('action:http');
    assert.equal(local.value('url'),'{{ $("Сделки").url }}');
    assert.equal(wire.workflowActions[0].config.headers,'{"Authorization":"keep-secret"}');
    assert.deepEqual(wire.workflowActions[0].config.vendor,{keep:true});
    assert.equal(wire.workflowActions[1].config.id,'{{ $json.order_id }}');
    assert.equal(wire.definition.vendor,'preserve');
    assert.equal(Object.hasOwn(wire.workflowActions[1].config,'operation_variant'),false);
});

test('query mode and response presets update the JSON locally',async()=>{
    const {wire,local}=fixture();local.begin('action:read');
    local.set('with_preset','contacts');await Promise.resolve();
    local.set('with_preset','tags');await Promise.resolve();
    local.set('with_preset','contacts');await Promise.resolve();
    assert.deepEqual(wire.workflowActions[1].config.parameters,[{name:'with',value:'contacts,tags'}]);
    local.set('operation_variant','leads.list');await Promise.resolve();
    assert.equal(wire.workflowActions[1].config.operation,'leads.list');
    assert.equal(wire.workflowActions[1].config.body_mode,'builder');
});

test('Cancel restores only the current node and preserves other draft edits',async()=>{
    const {wire,local}=fixture();local.begin('action:http');local.set('url','changed');await Promise.resolve();
    local.begin('action:read');local.set('id','123');await Promise.resolve();local.cancel();
    assert.equal(wire.workflowActions[1].config.id,'{{ $json.id }}');
    assert.equal(wire.workflowActions[0].config.url,'changed');
});

test('a condition with no rows opens with one blank rule and keeps the workflow unchanged until editing',()=>{
    const {wire,writes,local}=fixture();
    wire.workflowActions[2].config.conditions=[];
    const field=local.boot.schemas['action:control-condition'][0];
    field.minItems=1;
    field.children[1].default='equals';
    local.begin('action:end');
    assert.deepEqual(local.value('conditions'),[{operator:'equals'}]);
    assert.deepEqual(wire.workflowActions[2].config.conditions,[]);
    assert.deepEqual(writes,[]);
    local.set('conditions.0.left','{{ $json.id }}');
    local.flush();
    local.begin('action:http');
    local.begin('action:end');
    assert.deepEqual(local.value('conditions'),[{operator:'equals',left:'{{ $json.id }}'}]);
    assert.equal(wire.workflowActions[2].config.conditions.length,1);
});

test('conditional schema rules support numeric select IDs and repeater parents',()=>{
    const rule={dependency:'filters.*.field',cases:[{value:'statuses',result:{dependency:'filters.*.pipeline_id',cases:[{value:123,result:{142:'Успех'}}],fallback:{}}}],fallback:false};
    assert.deepEqual(inspectorRule(rule,{filters:[{field:'statuses',pipeline_id:'123'}]},[0]),{142:'Успех'});
    assert.equal(inspectorRule(rule,{filters:[{field:'name'}]},[0]),false);
});

test('reordering rows remains local and preserves row contents',()=>{
    const {wire,local}=fixture();local.begin('action:http');
    const field={path:'form_fields',reorderable:true};
    local.set('form_fields',[{name:'first',value:'{{ $json.a }}'},{name:'second',value:'{{ $json.b }}'}]);
    local.reorder(field,[],0,1);
    assert.deepEqual(wire.workflowActions[0].config.form_fields,[{name:'second',value:'{{ $json.b }}'},{name:'first',value:'{{ $json.a }}'}]);
});

test('errors clear after correction and do not follow a different node',()=>{
    const {local}=fixture();local.panel={error:''};local.errors['action:http']={url:['Required']};
    local.begin('action:http');assert.equal(local.panel.error,'Required');
    local.set('url','https://example.test/changed');assert.equal(local.panel.error,'');
    local.begin('action:read');assert.equal(local.panel.error,'');
});

test('choosing an item key pre-fills the next collection rule locally',()=>{
    const {local}=fixture();local.begin('action:http');local.set('__filter_item_field','status_id');
    const field={path:'rules',maxItems:50,children:[{path:'rules.*.field',kind:'value',children:[],default:{copyValue:'__filter_item_field'}}]};
    local.add(field,[]);assert.equal(local.value('rules.0.field'),'status_id');
});

test('the data picker uses recorded ancestors before the inspected pass, never future results',()=>{
    const {wire,local}=fixture();
    wire.debugState={results:[{id:'http',output:{body:{id:7}}},{id:'read',output:{id:8}},{id:'http',output:{body:{id:999}}}]};
    wire.debugSelectedResultIndex=1;
    local.begin('action:read');
    const source=local.sources().find(source=>source.id==='http');
    assert.equal(source.fields.find(field=>field.key==='.body.id').value,7);
    assert.equal(local.sources().some(source=>source.id==='end'),false);
    assert.equal(wire.debugState.results.length,3);
});

test('collection suggestions retain the whole collection and first-level item fields',()=>{
    const source=inspectorSource('read','Сделки',[{id:7,name:'A',nested:{id:1}},{id:8,price:10}],true);
    assert.deepEqual(source.fields[0].item_fields,['id','name','nested','nested.id','price']);
    assert.equal(source.fields.length,1,'Collection rows must not expand a huge input tree');
    assert.equal(source.fields[0].expression,'{{ $("Сделки") }}');
    assert.ok(source.fields.filter(field=>field.key.startsWith('[')).every(field=>field.expression===null));
});

test('editing a condition preserves all nested branches and unknown configuration keys',async()=>{
    const {wire,local}=fixture();
    wire.workflowActions[2].config.true_actions=[{id:'child',type:'workflow_delay',config:{seconds:1,private_setting:'keep'}}];
    local.begin('action:end');local.set('conditions.0.right','updated');await Promise.resolve();
    assert.deepEqual(wire.workflowActions[2].config.true_actions,[{id:'child',type:'workflow_delay',config:{seconds:1,private_setting:'keep'}}]);
});

test('data suggestions omit sensitive keys and inspect a bounded collection sample',()=>{
    const source=inspectorSource('a','Данные',{headers:{Authorization:'never-show'},items:Array.from({length:50000},()=>({id:1,token:'never-show',nested:{password:'never-show',name:'A'}}))},true);
    assert.equal(source.fields.length,2);
    assert.deepEqual(source.fields[1].item_fields,['id','nested','nested.name']);
    assert.equal(JSON.stringify(source).includes('never-show'),false);
});


test('lead settings show core CRM fields and retain optional mappings under extra settings', () => {
    const scalar = path => ({kind:'value',path});
    const extras = {kind:'section',label:'Поля сделки',children:[{kind:'repeater',path:'fields',children:[scalar('fields.*.value')]}]};
    const schema = [
        {kind:'section',children:[scalar('name'),scalar('price'),{kind:'group',children:[scalar('pipeline_id'),scalar('status_id')]},scalar('responsible_user_id'),scalar('tags'),scalar('contact_id'),scalar('company_id')]},
        extras,
        {kind:'hidden',path:'internal'},
    ];
    const before=JSON.stringify(schema);
    const {primary,secondary}=compactInspectorSections(schema, 'amocrm_create_lead');
    assert.deepEqual(primary[0].children.filter(field=>field.path).map(field=>field.path),['name','responsible_user_id','contact_id']);
    assert.deepEqual(primary[0].children[1].children.map(field=>field.path),['pipeline_id','status_id']);
    assert.deepEqual(secondary[0].children.filter(field=>field.path).map(field=>field.path),['price','tags','company_id']);
    assert.deepEqual(secondary[1],{...extras,collapsible:false});
    assert.equal(JSON.stringify(schema),before);
});


test('known action output paths stay selectable after a partial output without stale values', () => {
    const actual=inspectorSource('read','Получить контакт',{id:41},true);
    const known=inspectorSource('read','Получить контакт',{id:7,last_name:'Old'},true);
    const source=withKnownInspectorPaths(actual,known);
    assert.equal(source.fields.find(field=>field.key==='.id').value,41);
    const missed=source.fields.find(field=>field.key==='.last_name');
    assert.equal(missed.value,null);
    assert.equal(missed.available,false);
    assert.equal(missed.expression,'{{ $("Получить контакт").last_name }}');
});


test('editing a filter in a saved parameter request activates the unified query and retains raw parameters',()=>{
    const {wire,local}=fixture();
    local.boot.queryOperations.push('contacts.list');
    wire.workflowActions[1].config={operation:'contacts.list',body_mode:'fields',parameters:[{name:'with',value:'leads'}]};
    local.begin('action:read');
    assert.equal(local.config.body_mode,'fields');
    local.set('filters',[{field:'responsible_user_id',operator:'eq',value:9}]);
    local.flush();
    assert.equal(wire.workflowActions[1].config.body_mode,'builder');
    assert.deepEqual(wire.workflowActions[1].config.parameters,[{name:'with',value:'leads'}]);
    assert.equal(wire.workflowActions[1].config.limit,50);
    assert.equal(wire.workflowActions[1].config.page,1);
    local.begin('action:http');local.begin('action:read');
    assert.deepEqual(local.config.filters,[{field:'responsible_user_id',operator:'eq',value:9}]);
});
