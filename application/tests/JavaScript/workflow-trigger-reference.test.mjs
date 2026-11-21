import test from 'node:test';
import assert from 'node:assert/strict';
import {WorkflowLocalInspector, inspectorTriggerSource, triggerReferenceRows} from '../../resources/js/workflow-local-inspector.mjs';

const shape = {id:null, status_id:null, author:{name:null}, custom_fields:[{id:null}]};
const reference = {label:'Смена статуса',shape,fields:{id:'ID сделки','author.name':'Автор'},body:{leads:{status:[shape]}}};

test('event structure renders only paths and collapses custom field example shape by default', () => {
    const rows = triggerReferenceRows(reference.body);
    assert.deepEqual(rows.map(({label,depth}) => [label,depth]), [
        ['leads',0],['status',1],['0',2],['id',3],['status_id',3],['author',3],['name',4],['custom_fields',3],
    ]);
    assert.ok(rows.every(row => !Object.hasOwn(row,'value')));
    assert.equal(rows.at(-1).expanded,false);
    assert.equal(triggerReferenceRows(reference.body, {'/leads':true}).length, 1);
});

test('unreceived or partial events expose stable short substitutions without invented values', () => {
    for (const input of [undefined,{id:12,author:null,other:false,token:'not exposed'}]) {
        const source = inspectorTriggerSource('trigger','Событие',reference,input);
        const fields = new Map(source.fields.map(field => [field.key,field]));
        assert.equal(fields.get('.status_id').expression, '{{ $("Событие").status_id }}');
        assert.equal(fields.get('.id').value,input?.id ?? null);
        assert.equal(fields.get('.author.name').available,false);
        assert.equal(fields.has('.token'),false);
        assert.equal(fields.get('.custom_fields').available,false);
        assert.equal(fields.get('.custom_fields').expression,'{{ $("Событие").custom_fields }}');
    }
});

test('additional connected start shapes work before a run without borrowing primary values', () => {
    const local = new WorkflowLocalInspector({
        trigger:{type:'manual'},
        definition:{additional_triggers:[{id:'trigger:event',type:'amocrm-status-lead',name:'CRM'}],connections:[{sourceId:'trigger:event',targetId:'action:next'}]},
        workflowActions:[{id:'next',type:'workflow_delay'}],
        debugState:{display_trigger_data:{id:77},start_node_id:'trigger'},
    },{sources:[],triggerReferences:{'amocrm-status-lead':reference}});
    local.id = 'action:next';
    const source = local.sources().find(item => item.id === 'trigger:event');
    assert.equal(source.fields.find(field => field.key === '.id').expression,'{{ $("CRM").id }}');
    assert.equal(source.available,false);
});
