import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';

function fixture() {
    const stores = {}, interceptors = [];
    const window = {
        Alpine: {store(name, value) { if (value) stores[name] = value; return stores[name]; }},
        Livewire: {interceptAction(callback) { interceptors.push(callback); }},
    };
    const source = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
    runInNewContext(source.slice(0, source.indexOf('window.workflowExpressionParts =')), {window, document: {addEventListener() {}}});
    window.initializeWorkflowNodeLoading();
    assert.equal(interceptors.length, 1, 'navigation must not register a second handler');
    const wire = {$id: 'editor-1', editingActionId: 'contact', nodePreviewTaskId: null, nodePreviewProgress: {}};
    return {
        wire, stores, manualRunning: () => window.workflowManualIsRunning(wire), running: (nodeId = null, editor = wire) => window.workflowNodeIsRunning(editor, nodeId),
        start(name, params = [], editor = wire) {
            let finish;
            interceptors[0]({action: {name, params, component: {id: editor.$id, reactive: editor}}, onFinish(callback) { finish = callback; }});
            return finish;
        },
    };
}

for (const method of ['runWorkflowCanvasNode', 'runEditingWorkflowNode']) {
    test(`${method}: immediate spinner belongs only to the requested node and editor`, () => {
        const f = fixture();
        const finish = f.start(method, ['contact']);
        assert.equal(f.running(), true);
        assert.equal(f.running('contact'), true);
        assert.equal(f.running('other'), false);
        assert.equal(f.running('contact', {...f.wire, $id: 'editor-2'}), false);
        // Changing the inspected node must not move the pending indicator.
        f.wire.editingActionId = 'other';
        assert.equal(f.running('contact'), true);
        finish();
        finish();
        assert.equal(f.running(), false);
        assert.equal(Object.keys(f.stores.workflowNodeRuns.pending).length, 0);
    });
}

test('overlapping requests keep the indicator until both finish; unrelated actions do not start it', () => {
    const f = fixture();
    assert.equal(f.start('save'), undefined);
    assert.equal(f.start('pollWorkflowNodePreview'), undefined);
    const first = f.start('runWorkflowCanvasNode', ['contact']);
    const second = f.start('runWorkflowCanvasNode', ['contact']);
    first();
    assert.equal(f.running('contact'), true);
    second();
    assert.equal(f.running(), false);
});

test('manual flow has immediate feedback, stays busy while queued and never marks every node running', () => {
    const f = fixture();
    const finish = f.start('runWorkflowManually');
    assert.equal(f.manualRunning(), true);
    assert.equal(f.running(), true);
    assert.equal(f.running('contact'), false);
    f.wire.manualRunStatus = 'pending';
    finish();
    for (const state of ['pending', 'running']) {
        f.wire.manualRunStatus = state;
        assert.equal(f.manualRunning(), true);
        assert.equal(f.running(), true);
    }
    for (const state of ['completed', 'failed', 'cancelled', 'paused', null]) {
        f.wire.manualRunStatus = state;
        assert.equal(f.manualRunning(), false);
        assert.equal(f.running(), false);
    }
    const failed = f.start('runWorkflowManually');
    failed();
    assert.equal(f.manualRunning(), false);
});

test('loop spinner survives the queued response and polls, then clears on completion or cancellation', () => {
    for (const outcome of ['completed', 'failed', 'cancelled', 'expired']) {
        const f = fixture();
        const finish = f.start('runWorkflowCanvasNode', ['loop']);
        f.wire.nodePreviewTaskId = 'task';
        f.wire.nodePreviewProgress = {node_id: 'loop', status: 'queued'};
        finish();
        assert.equal(f.running('loop'), true);
        assert.equal(f.running('contact'), false);
        f.wire.nodePreviewProgress.status = 'running';
        assert.equal(f.running('loop'), true);
        f.wire.nodePreviewProgress.status = outcome;
        f.wire.nodePreviewTaskId = null;
        f.wire.nodePreviewProgress = {};
        assert.equal(f.running(), false);
    }
});

test('the installed Livewire finishes actions for success, cancellation, HTTP and network errors', () => {
    const framework = readFileSync(new URL('../../vendor/livewire/livewire/dist/livewire.js', import.meta.url), 'utf8');
    // This is the framework lifecycle our UI subscribes to, not a success-only promise.
    for (const method of ['invokeOnCancel()', 'invokeOnFailure(error2)', 'invokeOnError({ response, body, preventDefault })']) {
        const message = framework.slice(framework.indexOf('var Message = class'));
        const body = message.slice(message.indexOf(`    ${method} {`)).split('\n    }')[0];
        assert.ok(body.includes('action.invokeOnFinish()'), method);
    }
    assert.match(framework, /onFinish: \(cb\) => action\.onFinishCallbacks\.push\(cb\)/);
});

test('loading keeps the button opaque and replaces old results without a floating panel', () => {
    const css = readFileSync(new URL('../../resources/css/filament-workflows.css', import.meta.url), 'utf8');
    assert.match(css, /\.workflow-node-run__button\[aria-busy="true"\]\s*\{ opacity: 1;/);
    assert.match(css, /\.workflow-node-output\[aria-busy="true"\][^\n]+display: none/);
    const output = readFileSync(new URL('../../resources/views/filament/workflow-builder/workflow-node-output.blade.php', import.meta.url), 'utf8');
    assert.match(output, /workflow-node-output__loading" role="status" aria-live="polite"/);
    const progress = readFileSync(new URL('../../resources/views/filament/workflow-builder/workflow-preview-progress.blade.php', import.meta.url), 'utf8');
    assert.match(progress, /workflow-run-spinner/);
    assert.doesNotMatch(progress, /position:\s*fixed|workflow-preview-progress-float/);
});
