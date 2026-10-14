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
        wire, stores, adding: (editor = wire) => window.workflowNodeIsAdding(editor), saving: (editor = wire) => window.workflowIsSaving(editor), running: (nodeId = null, editor = wire) => window.workflowNodeIsRunning(editor, nodeId),
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
    const saved = f.start('save');
    assert.equal(f.running(), false, 'Saving must not show node execution indicators');
    saved();
    assert.equal(f.start('pollWorkflowNodePreview'), undefined);
    const first = f.start('runWorkflowCanvasNode', ['contact']);
    const second = f.start('runWorkflowCanvasNode', ['contact']);
    first();
    assert.equal(f.running('contact'), true);
    second();
    assert.equal(f.running(), false);
});

for (const method of ['save', 'create']) {
    test(`${method}: saving starts immediately, is editor-scoped and finishes on any outcome`, () => {
        const f = fixture();
        assert.equal(f.saving(), false);
        const finish = f.start(method);
        assert.equal(f.saving(), true);
        assert.equal(f.saving({...f.wire, $id: 'another-editor'}), false);
        assert.equal(f.running(), false);
        const unrelated = f.start('runEditingWorkflowNode');
        unrelated();
        assert.equal(f.saving(), true, 'Other completed requests cannot stop the spinner');
        finish();
        finish();
        assert.equal(f.saving(), false);
        assert.equal(Object.keys(f.stores.workflowNodeRuns.saving).length, 0);
        const retry = f.start(method);
        assert.equal(f.saving(), true);
        retry();
        assert.equal(f.saving(), false);
    });
}

test('overlapping save requests keep feedback until every request has finished', () => {
    const f = fixture();
    const first = f.start('save'), second = f.start('save');
    first();
    assert.equal(f.saving(), true);
    second();
    assert.equal(f.saving(), false);
});

for (const method of ['selectTriggerType', 'selectActionType', 'selectReadOperation']) {
    test(`${method}: catalog feedback starts immediately and ends on every request outcome`, () => {
        const f = fixture();
        assert.equal(f.adding(), false);
        const finish = f.start(method, ['schedule', true]);
        assert.equal(f.adding(), true);
        assert.equal(f.adding({...f.wire, $id: 'another-editor'}), false);
        assert.equal(f.running(), false);
        assert.equal(f.saving(), false);
        const save = f.start('save');
        save();
        assert.equal(f.adding(), true);
        finish();
        finish();
        assert.equal(f.adding(), false);
        const retry = f.start(method, ['schedule', true]);
        assert.equal(f.adding(), true);
        retry();
        assert.equal(f.adding(), false);
    });
}

test('all catalog insertion buttons block double clicks and expose accessible pending feedback', () => {
    const view = readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflows/node-library.blade.php', import.meta.url), 'utf8');
    const insertButtons = [...view.matchAll(/<button\b[^>]*(?:selectTriggerType|selectActionType|selectReadOperation)[^>]*>/gs)];
    assert.equal(insertButtons.length, 8);
    for (const [button] of insertButtons) assert.match(button, /x-bind:disabled="workflowNodeIsAdding\(\$wire\)"/);
    assert.match(view, /x-bind:aria-busy="workflowNodeIsAdding\(\$wire\)"/);
    assert.match(view, /workflow-node-library__pending" role="status" aria-live="polite"/);
    assert.match(view, /workflow-node-replaced.window="open = false; anchor = null"/);
});

test('save feedback is accessible, disables repeat submits and reserves its width', () => {
    const view = readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflow-builder.blade.php', import.meta.url), 'utf8');
    assert.match(view, /x-bind:disabled="workflowIsSaving\(\$wire\)"/);
    assert.match(view, /x-bind:aria-busy="workflowIsSaving\(\$wire\)"/);
    assert.match(view, /x-bind:aria-label="workflowIsSaving\(\$wire\) \? 'Сохраняю…'/);
    assert.match(view, /wire:target="save,create"/);
    assert.match(view, /workflow-save-button__progress" role="status" aria-live="polite"/);
    const css = readFileSync(new URL('../../resources/css/filament-workflows.css', import.meta.url), 'utf8');
    assert.match(css, /\.workflow-save-button__content\s*\{ display: inline-grid;/);
    assert.match(css, /\.workflow-save-button__label\s*\{ grid-area: 1 \/ 1;/);
    assert.match(css, /\.workflow-save-button__progress\s*\{ visibility: hidden;/);
    assert.match(css, /\.workflow-save-button\[aria-busy="true"\][^\n]+opacity: 1; cursor: wait/);
});

test('removed standalone flow run has no loading handler or queued state', () => {
    const f = fixture();
    assert.equal(f.start('runWorkflowManually'), undefined);
    f.wire.manualRunStatus = 'pending';
    assert.equal(f.running(), false);
    assert.equal(f.running('contact'), false);
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
