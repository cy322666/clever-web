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
    const wire = {definition: clone(definition), trigger: clone(definition.trigger), workflowActions: clone(definition.actions), mountedActions: [],
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
    return {draft: new WorkflowEditorDraft(wire, catalog, () => {}, (name, detail) => events.push({name, detail})), wire, writes, definition, events};
}
test('local editing never requests persistence and preserves nested branches and unknown keys', () => {
    const {draft, wire, definition} = fixture();
    draft.renameWorkflow('Переименован'); draft.setWorkflowTags([' тег ', 'тег']); draft.setWorkflowFolder('Папка');
    draft.renameWorkflowNode('a', 'Ожидание');
    assert.equal(wire.workflowActions[0].config.true_actions[0].name, 'Ожидание');
    assert.equal(wire.workflowActions[1].config.seconds, '{{ $("Ожидание").seconds }}');
    assert.equal(wire.workflowActions[0].custom_key, 'keep');
    assert.deepEqual(wire.definition.vendor_metadata, definition.vendor_metadata);
    assert.deepEqual(wire.definition.canvas_areas, definition.canvas_areas);
    assert.equal('connections' in wire.definition, false, 'A rename must not convert a legacy tree');
    assert.deepEqual(wire.definition.tags, ['тег']);
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
test('invalid local rename preserves the document; open form still uses existing server validation', async () => {
    const {draft, wire} = fixture();
    const before = clone(wire.definition);
    assert.throws(() => draft.renameWorkflowNode('a', 'Следующий'), /названием/);
    assert.deepEqual(wire.definition, before);
    wire.mountedActions = [{name: 'configureWorkflowAction'}];
    let calls = 0;
    wire.renameWorkflowNode = async (...args) => {calls++; assert.deepEqual(args, ['a', 'Новое', true]); return 'Новое';};
    assert.equal(await draft.renameWorkflowNode('a', 'Новое', true), 'Новое');
    assert.equal(calls, 1);
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
