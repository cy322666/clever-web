import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';
import test from 'node:test';

function fixture() {
    const tasks = [], events = new Map();
    let observer, options, scans = 0;
    const document = {
        documentElement: {},
        addEventListener: (name, callback) => events.set(name, callback),
        querySelectorAll: () => { scans++; return []; },
    };
    runInNewContext(readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8'), {
        window: {addEventListener() {}}, document,
        queueMicrotask: callback => tasks.push(callback),
        requestAnimationFrame: () => assert.fail('Expression color must not wait for another frame'),
        MutationObserver: class {
            constructor(callback) { observer = callback; }
            observe(root, config) { options = config; }
        },
    });
    const flush = () => { while (tasks.length) tasks.shift()(); };
    flush();
    return {tasks, events, notify: records => observer(records), options, flush, scans: () => scans};
}

test('revealing an inspector or x-show tab repaints before the next frame without user input', () => {
    const f = fixture();
    for (const attributeName of ['hidden', 'style', 'class', 'type', 'value']) {
        assert.ok(f.options.attributeFilter.includes(attributeName));
        const target = {closest: selector => selector === '.workflow-node-settings' ? {} : null};
        f.notify([{type: 'attributes', attributeName, target}]);
        assert.equal(f.tasks.length, 1);
        const before = f.scans(); f.flush(); assert.equal(f.scans(), before + 1);
    }
});

test('a form mounted inside a previously hidden ancestor is colored in the same render turn', () => {
    const f = fixture();
    f.notify([{type: 'childList', target: {querySelector: selector => selector === '.workflow-node-settings' ? {} : null}}]);
    assert.equal(f.tasks.length, 1);
    f.flush(); assert.equal(f.scans(), 2);
});

test('overlay mutations and unrelated canvas transforms do not cause paint loops', () => {
    const f = fixture();
    f.notify([
        {type: 'attributes', attributeName: 'style', target: {closest: () => ({}), querySelector: () => ({})}},
        {type: 'attributes', attributeName: 'style', target: {closest: () => null, querySelector: () => null}},
    ]);
    assert.equal(f.tasks.length, 0);
});

test('removing the whole settings panel still schedules cleanup of disconnected fields', () => {
    const f = fixture();
    f.notify([{type: 'childList', target: {}, removedNodes: [{matches: selector => selector === '.workflow-node-settings'}]}]);
    assert.equal(f.tasks.length, 1);
    f.flush(); assert.equal(f.scans(), 2);
});

test('several input and visibility updates share one paint pass', () => {
    const f = fixture();
    f.events.get('input')(); f.events.get('change')(); f.events.get('alpine:initialized')();
    assert.equal(f.tasks.length, 1);
    f.flush(); assert.equal(f.scans(), 2);
});
