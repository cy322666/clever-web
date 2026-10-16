import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';
import test from 'node:test';

function fixture() {
    const source = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
    const window = {};
    runInNewContext(source.slice(source.indexOf('window.workflowWorkbench ='), source.indexOf('window.workflowNodeCanvas =')), {window});
    const workbench = window.workflowWorkbench();
    workbench.$wire = {
        data: {name: 'Flow', group_name: null},
        definition: {version: 2, canvas_layout: [], canvas_edge_routes: []},
        trigger: {type: 'manual', config: []},
        workflowActions: [{id: 'node', type: 'http_request', config: {body: [], timeout: 5}}],
    };
    const save = () => {
        const wire = workbench.$wire;
        wire.savedWorkflowEditorState = structuredClone({...wire.data,
            definition: {...wire.definition, trigger: wire.trigger, actions: wire.workflowActions}});
    };
    save();
    return {workbench, wire: workbench.$wire, save};
}

test('empty layout dictionaries remain clean after PHP roundtrip and canvas refresh', () => {
    const {workbench, wire} = fixture();
    assert.equal(workbench.hasUnsavedChanges, false);
    wire.definition.canvas_layout = {};
    wire.definition.canvas_edge_routes = {};
    assert.equal(workbench.hasUnsavedChanges, false);
    delete wire.definition.canvas_layout;
    delete wire.definition.canvas_edge_routes;
    assert.equal(workbench.hasUnsavedChanges, false);
});

test('absolute layout ignores derived offsets after reload but tracks real node movement', () => {
    const {workbench,wire,save}=fixture();
    wire.definition.canvas_positions={'action:node':{x:240,y:96}};
    assert.equal(workbench.hasUnsavedChanges,false,'Adding coordinate metadata alone is not a user edit');
    save();
    wire.definition.canvas_layout={'action:node':{x:-500,y:120}};
    assert.equal(workbench.hasUnsavedChanges,false,'DOM reflow changes translations, not card coordinates');
    wire.definition.canvas_positions['action:node'].x=250;
    assert.equal(workbench.hasUnsavedChanges,true);
    save();
    assert.equal(workbench.hasUnsavedChanges,false);
});

test('actual edits stay dirty until acknowledged; reverting restores clean state', () => {
    for (const change of [
        wire => {wire.data.name = 'Renamed';},
        wire => {wire.data.group_name = 'Folder';},
        wire => {wire.workflowActions[0].config.timeout = 10;},
        wire => {wire.workflowActions[0].config.body = {};},
        wire => {wire.trigger.type = 'schedule';},
        wire => {wire.definition.canvas_layout = {'action:node': {x: 10, y: 20}};},
        wire => {wire.definition.canvas_edge_routes = {edge: {kind: 'curve', x: 100, y: 200}};},
        wire => {wire.definition.canvas_areas = [{id:'area_contact',title:'Контакт',x:0,y:0,width:640,height:360}];},
        wire => {wire.workflowActions.push({id: 'second', type: 'workflow_delay'});},
    ]) {
        const {workbench, wire, save} = fixture();
        change(wire);
        assert.equal(workbench.hasUnsavedChanges, true);
        // Failed saves / unrelated server responses do not advance the baseline.
        wire.debugState = {status: 'completed'};
        assert.equal(workbench.hasUnsavedChanges, true);
        save();
        assert.equal(workbench.hasUnsavedChanges, false);
    }
    const {workbench, wire} = fixture();
    wire.data.name = 'Other';
    wire.data.name = 'Flow';
    assert.equal(workbench.hasUnsavedChanges, false);
});

test('renaming, resizing and deleting areas marks the draft dirty until saved', () => {
    for (const edit of [areas => {areas[0].title = 'Сделка';}, areas => {areas[0].width = 800;}, areas => {areas.splice(0, 1);}]) {
        const {workbench, wire, save} = fixture();
        wire.definition.canvas_areas = [{id:'area_contact',title:'Контакт',x:0,y:0,width:640,height:360}];
        save();
        edit(wire.definition.canvas_areas);
        assert.equal(workbench.hasUnsavedChanges, true);
        save();
        assert.equal(workbench.hasUnsavedChanges, false);
    }
});

test('object key ordering is ignored while array order and value types are preserved', () => {
    const {workbench, wire, save} = fixture();
    wire.workflowActions[0].config = {timeout: 5, body: []};
    assert.equal(workbench.hasUnsavedChanges, false);
    wire.workflowActions[0].config.body = [1, 2];
    save();
    wire.workflowActions[0].config.body = [2, 1];
    assert.equal(workbench.hasUnsavedChanges, true);
    wire.workflowActions[0].config.body = [1, 2];
    wire.workflowActions[0].config.timeout = '5';
    assert.equal(workbench.hasUnsavedChanges, true);
    wire.savedWorkflowEditorState = null;
    assert.equal(workbench.hasUnsavedChanges, true);
});

test('an open inspector tracks field edits without falsely marking an untouched form dirty', () => {
    const {workbench,wire}=fixture();
    wire.workflowInspectorInitialData={seconds:5};
    wire.mountedActions=[{name:'configureWorkflowAction',data:{seconds:5}}];
    assert.equal(workbench.hasUnsavedChanges,false);
    wire.mountedActions[0].data.seconds=12;assert.equal(workbench.hasUnsavedChanges,true);
    wire.mountedActions[0].data.seconds=5;assert.equal(workbench.hasUnsavedChanges,false);
    wire.mountedActions=[];assert.equal(workbench.hasUnsavedChanges,false);
});

test('an incomplete draft in another node still marks the workflow unsaved', () => {
    const {workbench,wire}=fixture();
    wire.workflowInspectorDrafts={'action:previous':{data:{seconds:''},initial:{seconds:5}}};
    assert.equal(workbench.hasUnsavedChanges,true);
    wire.workflowInspectorDrafts={};
    assert.equal(workbench.hasUnsavedChanges,false);
});
