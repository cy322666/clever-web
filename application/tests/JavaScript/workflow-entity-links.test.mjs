import test from 'node:test';
import assert from 'node:assert/strict';
import {linkEntityType, linkedEntityOptions, clearConflictingLink} from '../../resources/js/workflow-entity-links.mjs';
import {WorkflowLocalInspector} from '../../resources/js/workflow-local-inspector.mjs';
import {setPath} from '../../resources/js/workflow-browser-document.mjs';
import {nodeFields} from '../../resources/js/workflow-browser-fields.mjs';

const entities={lead:'Сделка',contact:'Контакт',company:'Компания',customer:'Покупатель'};

test('linked entity lists exclude the same normalized type in both link actions', () => {
    for (const [source,alias] of [['lead','leads'],['contact','contacts'],['company','companies'],['customer','customers']]) {
        for (const value of [source,alias,' '+alias.toUpperCase()+' ']) {
            assert.equal(linkEntityType(value),source);
            const options=linkedEntityOptions(value,entities);
            assert.equal(Object.hasOwn(options,source),false);
            assert.equal(Object.keys(options).length,3);
            for (const type of ['amocrm_link_entity','amocrm_unlink_entity']) {
                const field=nodeFields({type,config:{target_entity:value}}, {}, {}).find(field=>field.path==='linked_entity');
                assert.equal(Object.hasOwn(field.options,source),false);
            }
        }
    }
    assert.deepEqual(linkedEntityOptions('{{ $json.entity }}',entities),entities);
    assert.equal(linkEntityType('constructor'),null);
});

test('source changes clear conflicting links while keeping valid links and unrelated config', () => {
    const config={target_entity:'lead',linked_entity:'leads',linked_entity_id:77,vendor:{keep:true}};
    clearConflictingLink(config);
    assert.equal(config.linked_entity,null);
    assert.equal(config.linked_entity_id,null);
    assert.deepEqual(config.vendor,{keep:true});
    const valid={target_entity:'lead',linked_entity:'contact',linked_entity_id:77};
    clearConflictingLink(valid);
    assert.equal(valid.linked_entity_id,77);
});

test('local source and linked type controls invalidate only IDs that belong to the changed type', async () => {
    for (const type of ['amocrm_link_entity','amocrm_unlink_entity']) {
        const wire={workflowActions:[{id:'link',type,config:{target_entity:'lead',target_entity_id:42,linked_entity:'contact',linked_entity_id:77,vendor:{keep:true}}}],trigger:{type:'manual',config:{}},definition:{connections:[]},workflowLocalInspectorDirty:{},$set(path,value){setPath(this,path,value)}};
        const local=new WorkflowLocalInspector(wire,{schemas:{['action:'+type]:[]},sources:[],conditionFields:[]});
        local.begin('action:link');
        local.set('target_entity','contact');await Promise.resolve();
        assert.equal(local.value('linked_entity'),null);
        assert.equal(local.value('linked_entity_id'),null);
        local.set('linked_entity','company');local.set('linked_entity_id',88);await Promise.resolve();
        local.set('target_entity','lead');await Promise.resolve();
        assert.equal(local.value('linked_entity'),'company');
        assert.equal(local.value('linked_entity_id'),88);
        local.set('linked_entity','customer');await Promise.resolve();
        assert.equal(local.value('linked_entity_id'),null);
        assert.deepEqual(wire.workflowActions[0].config.vendor,{keep:true});
    }
});
