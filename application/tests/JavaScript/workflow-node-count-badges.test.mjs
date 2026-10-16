import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';

function fixture() {
    const events = [];
    const window = {addEventListener() {}, dispatchEvent: event => events.push(event)};
    runInNewContext(readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8'), {
        window, document: {addEventListener() {}, querySelector: () => null},
        CustomEvent: class {constructor(type, props) {this.type = type; Object.assign(this, props);}},
    });
    const panel = window.workflowDebugPanel();
    panel.$wire = {$id: 'flow', debugOpen: false, debugState: {}, nodeRunResults: {}, workflowActions: []};
    const canvas = window.workflowNodeCanvas('badge-test');
    canvas.scheduleGraphRefresh = () => {};
    const badges = new Map();
    canvas.nodeElements = () => [...badges].map(([id, badge]) => ({
        dataset: {workflowNodeId: id}, querySelector: () => ({dataset: {}, querySelector: () => badge}),
    }));
    const badge = id => {
        const value = {hidden: true, textContent: ''};
        badges.set(id, value);
        return value;
    };
    const refresh = () => {
        panel.updateDebugSelection();
        canvas.setExecutionState(events.at(-1).detail.state);
    };
    return {panel, canvas, events, badge, refresh};
}

test('single-node execution displays entity counts without starting the step debugger', () => {
    const {panel, badge, refresh} = fixture();
    const count = badge('action:fetch');
    panel.$wire.nodeRunResults.fetch = {id: 'fetch', status: 'completed', display_count: 5};
    refresh();
    assert.equal(count.hidden, false);
    assert.equal(count.textContent, '5');
    assert.equal(count.title, 'Сущностей');
    assert.equal(panel.debugConfigured(), false, 'A preview must not create a debugger session');
    assert.deepEqual(panel.$wire.debugState, {}, 'Canvas projection must not mutate the debug session');
});

test('preview badges survive settings changes and closing the debug panel; reruns replace counts', () => {
    const {panel, badge, refresh} = fixture();
    const fetch = badge('action:fetch'), loop = badge('action:loop'), trigger = badge('trigger');
    panel.$wire.nodeRunResults = {
        fetch: {id: 'fetch', status: 'completed', display_count: 5},
        loop: {id: 'loop', status: 'completed', display_count: 1, count_kind: 'iterations'},
        trigger: {id: 'trigger', status: 'success', display_count: 1},
    };
    refresh();
    panel.$wire.debugOpen = true;
    panel.selectDebugNode('fetch');
    panel.$wire.debugOpen = false;
    refresh();
    assert.equal(fetch.hidden, false);
    assert.equal(loop.textContent, '1');
    assert.equal(loop.title, 'Итераций цикла');
    assert.equal(trigger.textContent, '1');
    panel.$wire.nodeRunResults.fetch = {id: 'fetch', status: 'completed', display_count: 0};
    refresh();
    assert.equal(fetch.textContent, '0');
    assert.equal(fetch.hidden, false, 'A genuine empty result still has a zero badge');
    assert.equal(loop.hidden, false, 'Running another node must keep earlier badges');
});

test('preview counts override the same node but preserve other recorded loop passes', () => {
    const {panel, badge, refresh} = fixture();
    const fetch = badge('action:fetch'), other = badge('action:other');
    panel.$wire.debugState = {status: 'completed', results: [
        {id: 'other', status: 'completed', display_count: 3},
        {id: 'fetch', status: 'completed', display_count: 2},
        {id: 'other', status: 'completed', display_count: 3},
    ]};
    panel.$wire.nodeRunResults.fetch = {id: 'fetch', status: 'completed', display_count: 7};
    refresh();
    assert.equal(fetch.textContent, '7');
    assert.equal(other.textContent, '3');
    assert.equal(panel.debugCanvasState().results.filter(result => result.id === 'other').length, 2);
    assert.equal(panel.$wire.debugState.results.length, 3);
});

test('inspecting a recorded pass keeps its exact result instead of a later preview', () => {
    const {panel, canvas, badge} = fixture();
    const fetch = badge('action:fetch');
    panel.$wire.debugOpen = true;
    panel.$wire.debugState = {status: 'ready', results: [
        {id: 'fetch', status: 'completed', display_count: 2, output: {passed: false}},
        {id: 'fetch', status: 'completed', display_count: 3, output: {passed: true}},
    ]};
    panel.$wire.nodeRunResults.fetch = {id: 'fetch', status: 'completed', display_count: 9};
    panel.selectDebugResult(0);
    canvas.setExecutionState(panel.debugCanvasState());
    assert.equal(fetch.textContent, '2');
    assert.equal(panel.debugCanvasState().results.at(-1).output.passed, false);
    assert.equal(panel.debugCanvasState().next_id, null);
});

test('reset clears badges and newly mounted cards receive existing preview counts', () => {
    const {panel, canvas, badge, refresh} = fixture();
    panel.$wire.nodeRunResults.fetch = {id: 'fetch', status: 'completed', display_count: 4};
    refresh();
    const fetch = badge('action:fetch');
    canvas.applyExecutionState();
    assert.equal(fetch.hidden, false);
    assert.equal(fetch.textContent, '4');
    panel.$wire.nodeRunResults = {};
    panel.$wire.debugState = {};
    refresh();
    assert.equal(fetch.hidden, true);
});

test('initial mount uses the same canvas projection as subsequent execution events', () => {
    const view = readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflow-builder.blade.php', import.meta.url), 'utf8');
    assert.match(view, /initializeCanvas\(\); setExecutionState\(debugCanvasState\(\)\)/);
    assert.match(view, /workflow-debug-updated\.window="setExecutionState\(debugCanvasState\(\)\)"/);
});
