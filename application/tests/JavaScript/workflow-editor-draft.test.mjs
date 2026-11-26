import test from 'node:test';
import assert from 'node:assert/strict';
import {WorkflowEditorDraft} from '../../resources/js/workflow-editor-draft.mjs';
import {clone, setPath} from '../../resources/js/workflow-browser-document.mjs';

function fixture() {
    const definition = {trigger: {type: 'manual', name: 'Старт', config: []}, actions: [
        {id: 'if', name: 'Условие', type: 'control-condition', custom_key: 'keep', config: {logic: 'and', true_actions: [
            {id: 'a', name: 'Пауза', type: 'workflow_delay', config: {seconds: 1, vendor_key: 'keep'}},
        ], false_actions: [], has_false_branch: true}},
        {id: 'b', name: 'Следующий', type: 'workflow_delay', config: {seconds: '{{ $("Пауза").seconds }}'}},
    ], vendor_metadata: {secret: 'never-drop'}, canvas_areas: [{id: 'area', title: 'Этап'}]};
    const writes = [], events = [];
    const remote = {definition: clone(definition), trigger: clone(definition.trigger), workflowActions: clone(definition.actions), mountedActions: [],
        data: {name: 'Поток'}, $set(path, value, live) {assert.equal(live, false); setPath(this, path, value); writes.push(path);},
        openWorkflowInspector() {assert.fail('Unexpected server call');},
    };
    const catalog = {actions: {workflow_delay: {type: 'workflow_delay', name: 'Пауза', defaults: {seconds: 2}},
        amocrm_read: {type: 'amocrm_read', name: 'Запрос', defaults: {parameters: []}}},
        triggers: {manual: {type: 'manual', name: 'Ручной запуск', defaults: []}},
        operations: {'leads.list': {node_name: 'Получить сделки'}}, names: {}, connections: [
            {sourceId: 'trigger', sourcePort: 'output', targetId: 'action:if'},
            {sourceId: 'action:if', sourcePort: 'yes', targetId: 'action:a'},
            {sourceId: 'action:a', sourcePort: 'output', targetId: 'action:b'},
        ]};
    const draft = new WorkflowEditorDraft(remote, catalog, () => {}, (name, detail) => events.push({name, detail}));
    // Simulate live UI reads/edits without sharing the transport snapshot.
    const wire = new Proxy(draft.wire, {set(target, path, value) {
        if (['mountedActions', 'renameWorkflowNode'].includes(path)) remote[path] = value;
        else target.$set(path, value, false);
        return true;
    }});
    return {draft, wire, remote, writes, definition, events};
}
test('local editing never requests persistence and preserves nested branches and unknown keys', () => {
    const {draft, wire, definition, remote, writes} = fixture();
    draft.renameWorkflow('Переименован'); draft.setWorkflowFolder('Папка');
    draft.renameWorkflowNode('a', 'Ожидание');
    assert.equal(wire.workflowActions[0].config.true_actions[0].name, 'Ожидание');
    assert.equal(wire.workflowActions[1].config.seconds, '{{ $("Ожидание").seconds }}');
    assert.equal(wire.workflowActions[0].custom_key, 'keep');
    assert.deepEqual(wire.definition.vendor_metadata, definition.vendor_metadata);
    assert.deepEqual(wire.definition.canvas_areas, definition.canvas_areas);
    assert.equal('connections' in wire.definition, false, 'A rename must not convert a legacy tree');
    assert.equal(wire.data.group_name, 'Папка');
    assert.equal(wire.definition.actions, wire.workflowActions);
    assert.equal(wire.definition.trigger, wire.trigger);
    assert.deepEqual(remote.definition, definition);
    assert.deepEqual(writes, []);
});

test('only an action that needs the draft receives a snapshot, and acknowledges only the edits it sent', () => {
    const {draft, remote, wire, definition, writes} = fixture();
    const canonical = {definition: clone(definition), trigger: clone(definition.trigger), workflowActions: clone(definition.actions),
        data: clone(remote.data), workflowLocalInspectorDirty: []};
    let intercept;
    remote.$interceptMessage = callback => { intercept = callback; };
    draft.attachTransport(remote);
    const send = method => {
        const message = {payload: {calls: [{method}], updates: {debugOpen: true}}, component: {canonical}};
        const body = {components: [message.payload]};
        message.request = {payload: body, options: {body: JSON.stringify(body)}};
        let success;
        intercept({message, onSuccess: callback => { success = callback; }});
        return {message, respond(server) {
            let sync;
            message.component.canonical = server;
            success?.({onSync: callback => { sync = callback; }});
            sync?.();
        }};
    };
    draft.renameWorkflow('Новый поток');
    draft.renameWorkflowNode('a', 'Ожидание');
    draft.inspectorChanged('action:a');
    wire.$set('definition.canvas_positions', {'action:b': {x: 100, y: 100}}, false);
    assert.deepEqual(send('pollWorkflowNodePreview').message.payload.updates, {debugOpen: true});
    const {message, respond} = send('save');
    const sent = message.payload.updates;
    assert.deepEqual(JSON.parse(message.request.options.body).components[0].updates, sent);
    assert.equal(sent['data.name'], 'Новый поток');
    assert.equal(sent.workflowActions[0].config.true_actions[0].name, 'Ожидание');
    assert.deepEqual(sent.workflowLocalInspectorDirty, {'action:a': true});
    assert.deepEqual(writes, []);

    const normalized = clone(sent.definition);
    normalized.actions[0].config.true_actions[0].config.seconds = '1';
    wire.$set('definition.canvas_positions', {'action:b': {x: 130, y: 100}}, false);
    draft.inspectorChanged('action:b');
    respond({...canonical, data: {name: 'Новый поток'}, definition: normalized, workflowActions: normalized.actions});
    assert.deepEqual(wire.definition.canvas_positions, {'action:b': {x: 130, y: 100}});
    assert.equal(wire.workflowActions[0].config.true_actions[0].config.seconds, '1');
    assert.deepEqual(Object.keys(wire.workflowLocalInspectorDirty), ['action:b']);
});
test('insertion splits the chosen edge locally without flattening existing nodes', () => {
    const {draft, wire, events} = fixture();
    wire.insertConnection = {sourceId: 'action:a', sourcePort: 'output', targetId: 'action:b'};
    const id = draft.selectActionType('workflow_delay');
    assert.equal(wire.workflowActions[0].config.true_actions[0].id, 'a');
    assert.equal(wire.workflowActions.at(-1).id, id.slice(7));
    assert.ok(wire.definition.connections.some(edge => edge.sourceId === 'action:a' && edge.targetId === id));
    assert.ok(wire.definition.connections.some(edge => edge.sourceId === id && edge.targetId === 'action:b'));
    assert.equal(wire.insertConnection, null);
    assert.equal(events.at(-1).name, 'workflow-node-inserted');
});
test('queries, triggers, copying and connecting are local and retain original IDs', () => {
    const {draft, wire} = fixture();
    const id = draft.selectReadOperation('leads.list');
    assert.equal(wire.workflowActions.at(-1).config.body_mode, 'builder');
    assert.equal(wire.workflowActions.at(-1).name, 'Получить сделки');
    draft.connectWorkflowNodes('action:b', 'output', id);
    assert.ok(wire.definition.connections.some(edge => edge.sourceId === 'action:b' && edge.targetId === id));
    assert.equal(draft.copyWorkflowNodes(['action:a', 'action:b']), true);
    const map = draft.pasteWorkflowNodes();
    assert.notEqual(map['action:a'], 'action:a');
    assert.equal(wire.workflowActions[0].config.true_actions[0].id, 'a');
    draft.beginTriggerReplace('trigger');
    assert.equal(draft.selectTriggerType('manual'), 'trigger');
    assert.equal(wire.trigger.type, 'manual');
});
test('the branch picker cannot insert a trigger, even with a stale menu selection', () => {
    const {draft, wire, events} = fixture();
    wire.insertConnection = {sourceId: 'action:if', sourcePort: 'no', targetId: null};
    assert.throws(() => draft.selectTriggerType('manual', true), /внутри ветки/);
    assert.equal(wire.definition.additional_triggers, undefined);
    draft.beginTriggerAdd();
    assert.equal(wire.insertConnection, null);
    assert.deepEqual(events.at(-1), {name: 'workflow-node-library-open', detail: {mode: 'trigger'}});
    assert.equal(draft.selectTriggerType('manual', true).startsWith('trigger:'), true);
});
test('rename remains local with an open form and still rejects duplicate names', async () => {
    const {draft, wire} = fixture();
    const before = clone(wire.definition);
    assert.throws(() => draft.renameWorkflowNode('a', 'Следующий'), /названием/);
    assert.deepEqual(wire.definition, before);
    wire.mountedActions = [{name: 'configureWorkflowAction'}];
    let calls = 0;
    wire.renameWorkflowNode = async () => {calls++; assert.fail('Opening a form must not make renaming remote');};
    assert.equal(await draft.renameWorkflowNode('a', 'Новое', true), 'Новое');
    assert.equal(calls, 0);
    assert.equal(wire.workflowActions[0].config.true_actions[0].name,'Новое');
});
test('copy is not an edit and keeps the complete document byte-for-byte unchanged', () => {
    const {draft, wire, writes} = fixture();
    const before = clone(wire.definition);
    draft.copyWorkflowNodes(['action:a']);
    assert.deepEqual(wire.definition, before);
    assert.deepEqual(writes, []);
    draft.renameWorkflowNode('a', 'Ожидание');
    assert.deepEqual(wire.trigger.config, []);
});
test('uncontrolled cycles are rejected locally without losing the valid graph', () => {
    const {draft, wire} = fixture();
    draft.connectWorkflowNodes('action:b', 'output', draft.selectActionType('workflow_delay'));
    const before = clone(wire.definition);
    assert.throws(() => draft.connectWorkflowNodes('action:b', 'output', 'action:if'), /цикл/);
    assert.deepEqual(wire.definition, before);
});
test('local loop return edits retain the same explicit-return guard as server edits', () => {
    const {draft, wire} = fixture();
    wire.workflowActions.push({id: 'loop', name: 'Цикл', type: 'workflow_loop', config: {require_return: false}});
    wire.definition.actions = clone(wire.workflowActions);
    wire.definition.connections = [{sourceId: 'action:loop', sourcePort: 'each', targetId: 'action:b'}];
    draft.connectWorkflowNodes('action:b', 'output', 'action:loop');
    assert.equal(wire.workflowActions.find(node => node.id === 'loop').config.require_return, true);
    wire.workflowActions.find(node => node.id === 'loop').config.require_return = false;
    draft.disconnectWorkflowNodes('action:b', 'output', 'action:loop');
    assert.equal(wire.workflowActions.find(node => node.id === 'loop').config.require_return, true);
    assert.equal(wire.definition.connections.some(edge => edge.targetId === 'action:loop'), false);
});

test('removing an action locally bridges its continuation and preserves unrelated node config', () => {
    const {draft,wire,events}=fixture();
    const remaining=clone(wire.workflowActions[1]);
    draft.removeWorkflowAction('a');
    assert.deepEqual(wire.workflowActions[1],remaining);
    assert.equal(wire.workflowActions[0].config.true_actions.length,0);
    assert.equal(wire.definition.connections.some(edge=>edge.sourceId==='action:a'||edge.targetId==='action:a'),false);
    assert.equal(wire.definition.connections.some(edge=>edge.sourceId==='action:if'&&edge.sourcePort==='yes'&&edge.targetId==='action:b'),true);
    assert.equal(events.at(-1).name,'workflow-connections-updated');
});

test('undoing an insertion restores its split connection and moved nodes without reverting later edits', () => {
    const {draft, wire, definition} = fixture();
    wire.definition.connections = clone(draft.catalog.connections);
    const original = clone(wire.definition.connections);
    wire.insertConnection = original.at(-1);
    wire.definition.canvas_positions = {'action:a':{x:100,y:200},'action:b':{x:300,y:200}};
    const inserted = draft.selectActionType('workflow_delay');
    const shifted = {'action:a':{x:100,y:200},'action:b':{x:570,y:200},[inserted]:{x:325,y:200}};
    draft.rememberInsertionPlacement(inserted, wire.definition.canvas_positions, shifted);
    wire.definition.canvas_positions = shifted;
    draft.renameWorkflowNode('a', 'Новое название');
    draft.connectWorkflowNodes('action:if', 'no', 'action:b');
    draft.updateWorkflowDescription('Новый текст');
    assert.equal(draft.undo(), true);
    assert.deepEqual(wire.definition.connections, [...original, {sourceId:'action:if',sourcePort:'no',targetId:'action:b'}]);
    assert.equal(wire.workflowActions.length, definition.actions.length);
    assert.equal(wire.workflowActions[0].config.true_actions[0].name, 'Новое название');
    assert.equal(wire.workflowActions[1].config.seconds, '{{ $("Новое название").seconds }}');
    assert.equal(wire.definition.description, 'Новый текст');
    assert.deepEqual(wire.definition.canvas_positions, {'action:a':{x:100,y:200},'action:b':{x:300,y:200}});
    assert.equal(draft.undo(), false);
});

test('undoing deletion restores a condition, live branch children, positions and pinned links', () => {
    const {draft, wire} = fixture();
    const original = clone(draft.catalog.connections);
    wire.definition.connections = original;
    const points = {'action:if':{x:100,y:200},'action:a':{x:300,y:200},'action:b':{x:550,y:340}};
    const key = JSON.stringify(['action:if','yes','action:a']);
    const routes = {[key]:{kind:'curve',x:160,y:240}};
    wire.definition.canvas_positions = clone(points);
    wire.definition.canvas_edge_routes = clone(routes);
    draft.removeWorkflowAction('if');
    assert.equal(wire.workflowActions.some(node => node.id === 'if'), false);
    draft.renameWorkflowNode('a', 'Новое имя');
    assert.equal(draft.undo(), true);
    assert.equal(wire.workflowActions[0].id, 'if');
    assert.equal(wire.workflowActions[0].config.true_actions[0].name, 'Новое имя');
    assert.deepEqual(wire.definition.connections, original);
    assert.deepEqual(wire.definition.canvas_positions, points);
    assert.deepEqual(wire.definition.canvas_edge_routes, routes);
});

test('undoing deletion of the primary start restores both triggers and their original connections', () => {
    const {draft, wire} = fixture();
    wire.definition.additional_triggers = [{id:'trigger:extra',type:'manual',name:'Запасной',config:[]}];
    const edges = [...draft.catalog.connections, {sourceId:'trigger:extra',sourcePort:'output',targetId:'action:if'}];
    wire.definition.connections = clone(edges);
    wire.definition.canvas_positions = {trigger:{x:100,y:200},'trigger:extra':{x:100,y:450}};
    draft.removeTriggerNode('trigger');
    assert.equal(wire.trigger.name, 'Запасной');
    draft.renameWorkflowNode('trigger', 'Бывший запасной');
    assert.equal(draft.undo(), true);
    assert.equal(wire.trigger.name, 'Старт');
    assert.equal(wire.definition.additional_triggers[0].name, 'Бывший запасной');
    assert.deepEqual(wire.definition.connections, edges);
    assert.deepEqual(wire.definition.canvas_positions, {trigger:{x:100,y:200},'trigger:extra':{x:100,y:450}});
});

test('undoing deletion of an additional start preserves the current primary start', () => {
    const {draft, wire} = fixture();
    wire.definition.additional_triggers = [{id:'trigger:extra',type:'manual',name:'Запасной',config:[]}];
    const edges = [...draft.catalog.connections, {sourceId:'trigger:extra',sourcePort:'output',targetId:'action:b'}];
    wire.definition.connections = clone(edges);
    draft.removeTriggerNode('trigger:extra');
    draft.renameWorkflowNode('trigger', 'Первый');
    assert.equal(draft.undo(), true);
    assert.equal(wire.trigger.name, 'Первый');
    assert.equal(wire.definition.additional_triggers[0].name, 'Запасной');
    assert.deepEqual(wire.definition.connections, edges);
});
