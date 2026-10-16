import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';

function fixture() {
    let observed, rendered;
    const element = () => ({
        attributes: {}, dataset: {}, classList: {add() {}},
        setAttribute(key, value) {this.attributes[key] = value;},
        appendChild() {}, addEventListener() {},
    });
    const window = {addEventListener() {}};
    runInNewContext(readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8'), {
        window, document: {addEventListener() {}, createElementNS: element},
        MutationObserver: class {constructor() {} disconnect() {} observe(el, options) {observed = options;}},
        ResizeObserver: class {disconnect() {} observe() {} unobserve() {}},
    });
    const canvas = window.workflowNodeCanvas();
    canvas.$wire = {$id: 'one', debugStartNodeId: 'trigger', debugState: {}, nodeRunResults: {}};
    const makeNode = (id, x) => {
        const rect = {left: x, top: 100, right: x + 96, bottom: 196, width: 96, height: 96};
        const card = {dataset: {}, getBoundingClientRect: () => rect, querySelector: () => null};
        return {dataset: {workflowNodeId: id}, card, querySelector: () => card};
    };
    const nodes = [makeNode('trigger', 0), makeNode('action:fetch', 240), makeNode('action:other', 480)];
    canvas.nodeElements = () => nodes;
    const control = {dataset: {workflowEdgeSource: 'trigger', workflowEdgePort: 'output', workflowEdgeTarget: 'action:fetch'}, style: {}};
    canvas.$refs = {
        stage: {clientWidth: 800, clientHeight: 500, querySelector: () => null, querySelectorAll: () => [], getBoundingClientRect: () => ({left: 0, top: 0})},
        edgeLayer: {style: {}, setAttribute() {}, replaceChildren(...elements) {rendered = elements;}},
        edgeControls: {querySelectorAll: () => [control]},
    };
    const draw = () => {
        canvas.drawConnections();
        return rendered.find(el => (el.attributes.class || '').split(' ').includes('workflow-node-edge'));
    };
    return {canvas, nodes, control, draw, observed: () => observed};
}

test('only the real incoming edge to a running node animates; finished paths stop', () => {
    const {canvas, nodes, draw} = fixture();
    assert.doesNotMatch(draw().attributes.class, /--running/);
    nodes[1].card.dataset.nodeRunning = 'true';
    const running = draw();
    assert.match(running.attributes.class, /workflow-node-edge--running/);
    assert.equal(running.attributes['marker-end'], 'url(#workflow-edge-arrow-running)');
    const geometry = running.attributes.d;
    nodes[1].card.dataset.nodeRunning = 'false';
    canvas.executionState = {results: [{id: 'fetch', status: 'completed', display_count: 1}]};
    const completed = draw();
    assert.doesNotMatch(completed.attributes.class, /--running/);
    assert.match(completed.attributes.class, /--completed/);
    assert.equal(completed.attributes.d, geometry, 'Animation must never move node ports or routes');
    nodes[2].card.dataset.nodeRunning = 'true';
    assert.doesNotMatch(draw().attributes.class, /--running/, 'An unrelated node must not animate this edge');
});

test('branch animation follows the actual current condition, not a past inspected result', () => {
    const {canvas, nodes, control, draw} = fixture();
    control.dataset.workflowEdgeSource = 'action:condition';
    control.dataset.workflowEdgePort = 'yes';
    nodes[0].dataset.workflowNodeId = 'action:condition';
    nodes[1].card.dataset.nodeRunning = 'true';
    canvas.executionState = {results: [{id: 'condition', status: 'completed', output: {passed: true}}]};
    canvas.$wire.debugState = {results: [{id: 'condition', status: 'completed', output: {passed: false}}]};
    assert.doesNotMatch(draw().attributes.class, /--running/);
    control.dataset.workflowEdgePort = 'no';
    assert.match(draw().attributes.class, /--running/);
    canvas.$wire.nodeRunResults.condition = {id: 'condition', status: 'completed', output: {passed: true}};
    assert.doesNotMatch(draw().attributes.class, /--running/);
    control.dataset.workflowEdgePort = 'yes';
    assert.match(draw().attributes.class, /--running/);
});

test('unknown or unchosen branches, placeholders and inactive history edges never animate', () => {
    const {canvas} = fixture();
    const running = new Set(['action:fetch']);
    const edge = {sourceId: 'action:if', sourcePort: 'yes', targetId: 'action:fetch'};
    assert.equal(canvas.edgeRunning(edge, undefined, running, new Map()), false);
    const results = new Map([['action:if', {status: 'skipped'}]]);
    assert.equal(canvas.edgeRunning(edge, undefined, running, results), true);
    assert.equal(canvas.edgeRunning({...edge, sourcePort: 'no'}, undefined, running, results), false);
    assert.equal(canvas.edgeRunning(edge, 'false', running, results), false);
    assert.equal(canvas.edgeRunning({...edge, targetId: null}, undefined, running, results), false);
    assert.equal(canvas.edgeRunning({...edge, sourceId: 'action:unexecuted', sourcePort: 'output'}, undefined, running, results), false,
        'Do not animate a loop return from a body that never ran');
    canvas.$wire.debugStartNodeId = 'trigger:other';
    assert.equal(canvas.edgeRunning({...edge, sourceId: 'trigger', sourcePort: 'output'}, undefined, running, results), false);
    assert.equal(canvas.edgeRunning({...edge, sourceId: 'trigger:other', sourcePort: 'output'}, undefined, running, results), true);
});

test('run state updates redraw paths once without an animation timer or server request', () => {
    const {canvas, observed} = fixture();
    canvas.observeCanvas();
    assert.ok(observed().attributeFilter.includes('data-node-running'));
    const source = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
    const draw = source.slice(source.indexOf('    drawConnections() {'), source.indexOf('window.workflowDebugPanel ='));
    assert.doesNotMatch(draw, /setInterval|fetch\(|\$call\(/);
});

test('card execution uses stronger geometry-safe pulse without a spinner and respects reduced motion', () => {
    const status = readFileSync(new URL('../../resources/views/filament/workflow-builder/workflow-node-status.blade.php', import.meta.url), 'utf8');
    assert.doesNotMatch(status, /workflow-run-spinner|workflow-node-status--running/);
    assert.match(status, /workflow-node-status--success/);
    assert.match(status, /workflow-node-status--error/);
    const pulse = readFileSync(new URL('../../resources/css/workflow-inspector.css', import.meta.url), 'utf8');
    assert.match(pulse, /\[data-node-running="true"\]::after\s*\{[^}]*border: 2px solid[^}]*pointer-events: none; animation: workflow-node-pulse 1s/s);
    assert.match(pulse, /@keyframes workflow-node-pulse[^\n]*opacity: \.95; scale: 1;[^\n]*scale: 1\.2;/);
    assert.match(pulse, /prefers-reduced-motion[^}]*\[data-workflow-node-card\]::after[^}]*animation: none !important/s);
    const edges = readFileSync(new URL('../../resources/css/filament-workflows.css', import.meta.url), 'utf8');
    assert.match(edges, /workflow-node-edge--running[^}]*stroke-dasharray: 8 6; animation: workflow-edge-flow/);
    assert.match(edges, /@media \(prefers-reduced-motion: reduce\) \{ \.workflow-node-edge\.workflow-node-edge--running \{ animation: none;/);
});
