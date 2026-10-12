import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

test('preview polling is always mounted for modal-only renders but never requests while idle', async () => {
    const source = readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflow-builder.blade.php', import.meta.url), 'utf8');
    const expression = source.match(/wire:poll\.2s="([^"]+)"/)[1];
    assert.ok(source.indexOf('wire:poll.2s') < source.indexOf('@if(($this->nodePreviewTaskId'));
    assert.ok(!source.includes('workflow-preview-progress-float'));
    let requests = 0;
    const wire = {nodePreviewTaskId: null, pollWorkflowNodePreview: async () => { requests++; }};
    const poll = new Function('$wire', `return ${expression};`);
    await poll(wire);
    assert.equal(requests, 0);
    wire.nodePreviewTaskId = 'queued-task';
    await poll(wire);
    assert.equal(requests, 1);
    wire.nodePreviewTaskId = null;
    await poll(wire);
    assert.equal(requests, 1);
});
