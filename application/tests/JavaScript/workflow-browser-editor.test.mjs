import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import {
    BrowserWorkflowDocument,
    clone,
    getPath,
    setPath,
    ports,
} from '../../resources/js/workflow-browser-document.mjs';
import { nodeFields } from '../../resources/js/workflow-browser-fields.mjs';

const source = readFileSync(
    new URL('../../resources/js/workflow-browser-editor.mjs', import.meta.url),
    'utf8',
)
    .replace(/^import\s+(?:[\s\S]*?\s+from\s+)?['"][^'"]+['"];?\n/gm, '')
    .replace('export function createBrowserEditor', 'function createBrowserEditor');
const document = () => ({
    id: 120,
    name: 'Тест',
    group_name: null,
    is_active: false,
    failure_strategy: 'stop',
    definition: {
        trigger: { type: 'manual', name: 'Старт', config: {} },
        actions: [{ id: 'a', type: 'workflow_delay', name: 'Пауза', config: { seconds: 1 } }],
        connections: [{ sourceId: 'trigger', sourcePort: 'output', targetId: 'action:a' }],
    },
});
function editor() {
    const context = {
        BrowserWorkflowDocument,
        clone,
        getPath,
        setPath,
        ports,
        nodeFields,
        console,
        setTimeout,
        clearTimeout,
        window: {
            workflowNodeCanvas: () => ({ saveNodeLayout() {}, destroy() {} }),
            history: { replaceState() {} },
            addEventListener() {},
            removeEventListener() {},
        },
        document: {},
        fetch: () => assert.fail('Unexpected network request'),
    };
    runInNewContext(source + '\nthis.factory=createBrowserEditor;', context);
    const result = context.factory({
        document: document(),
        version: 'old',
        actions: {},
        triggers: {},
        references: {},
        operations: {},
        readLibrary: {},
        folders: {},
        baseUrl: 'http://example.test',
        csrf: 'test',
    });
    result.$nextTick = (callback) => callback?.();
    result.$refs = {};
    result.renderInspector = () => {};
    result.notify = () => {};
    return result;
}

test('slow save acknowledges only the sent revision and never erases later typing', async () => {
    const state = editor();
    let respond;
    const sent = clone(state.doc);
    state.request = () =>
        new Promise((resolve) => {
            respond = resolve;
        });
    state.setDocument('name', 'Отправлено');
    const saving = state.saveDocument();
    state.setDocument('name', 'Напечатано позже');
    respond({ document: { ...sent, name: 'Отправлено' }, version: 'new' });
    await saving;
    assert.equal(state.doc.name, 'Напечатано позже');
    assert.equal(state.version, 'new');
    assert(state.hasUnsavedChanges);
    assert(!state.saving);
});

test('successful save stays clean and sends the whole document only once', async () => {
    const state = editor();
    let calls = 0;
    state.request = async (path, payload, method) => {
        calls++;
        assert.equal(path, '/120/document');
        assert.equal(method, 'PUT');
        assert.equal(payload.document.definition.actions[0].config.seconds, 1);
        return { document: clone(payload.document), version: 'next' };
    };
    state.setDocument('name', 'Новое');
    assert(await state.saveDocument());
    assert.equal(calls, 1);
    assert(!state.hasUnsavedChanges);
});

test('failed activation never claims that an unsaved draft was activated', async () => {
    const state = editor();
    state.request = async () => {
        throw new Error('Подключение отключено');
    };
    assert.equal(await state.saveDocument(true), false);
    assert.equal(state.doc.is_active, false);
    assert.equal(state.error, 'Подключение отключено');
    assert(!state.saving);
});

test('conflict preserves document, dirty state and version for manual recovery', async () => {
    const state = editor();
    state.setDocument('name', 'Мой черновик');
    state.request = async () => {
        throw new Error('Конфликт версий');
    };
    assert.equal(await state.saveDocument(), false);
    assert.equal(state.doc.name, 'Мой черновик');
    assert.equal(state.version, 'old');
    assert(state.hasUnsavedChanges);
});

test('local fields and rename do not request the server', () => {
    const state = editor();
    state.setDocument('definition.actions.0.config.seconds', 12);
    state.machine.rename('action:a', 'Новая пауза');
    assert.equal(state.doc.definition.actions[0].config.seconds, 12);
    assert(state.hasUnsavedChanges);
});

test('inspector reserves and releases canvas space on the editor root, never the clicked card', () => {
    const state = editor();
    const attributes = new Set();
    state.$refs.browserEditor = {
        toggleAttribute: (name, enabled) => (enabled ? attributes.add(name) : attributes.delete(name)),
        removeAttribute: (name) => attributes.delete(name),
    };
    state.$el = {
        toggleAttribute: () => assert.fail('Must not reserve space on a clicked card'),
        removeAttribute: () => assert.fail('Must not release space on the close button'),
    };
    state.applyNodeSelection = () => {};
    state.scheduleGraphRefreshAfterPaint = () => {};
    state.openNode('action:a');
    assert.equal(state.selectedId, 'action:a');
    assert(attributes.has('data-workflow-inspector-open'));
    state.closeInspector();
    assert.equal(state.selectedId, null);
    assert(!attributes.has('data-workflow-inspector-open'));
    assert(!state.hasUnsavedChanges);
});

test('variable insertion updates the active code editor instead of its hidden textarea', () => {
    const state = editor();
    state.selectedId = 'action:a';
    state.expressionOpen = true;
    let dispatched;
    let focused = false;
    state.lastFocusedField = {
        path: 'javascript_code',
        input: {
            isConnected: true,
            setRangeText: () => assert.fail('Must not write into the hidden textarea'),
        },
    };
    state.codeEditorView = () => ({
        state: { selection: { main: { from: 7, to: 12 } } },
        dispatch: (transaction) => {
            dispatched = transaction;
        },
        focus: () => {
            focused = true;
        },
    });
    state.insertExpression('{{ $json }}');
    assert.equal(dispatched.changes.from, 7);
    assert.equal(dispatched.changes.to, 12);
    assert.equal(dispatched.changes.insert, '{{ $json }}');
    assert.equal(dispatched.selection.anchor, 18);
    assert(focused);
    assert(!state.expressionOpen);
});

test('runtime errors are surfaced once without automatically retrying real actions', async () => {
    const state = editor();
    let calls = 0;
    state.request = async () => {
        calls++;
        throw new Error('Соединение потеряно');
    };
    await state.runDebug('node', 'action:a');
    assert.equal(calls, 1);
    assert.equal(state.error, 'Соединение потеряно');
    assert(!state.running);
});

test('one-node Play performs a real single-node request without silently simulating HTTP', async () => {
    const state = editor();
    let payload;
    state.request = async (path, data) => {
        payload = data;
        return { session: 'one', status: 'completed', results: [] };
    };
    state.setExecutionState = () => {};
    state.$refs.browserEditor = { toggleAttribute() {} };
    await state.runDebug('node', 'action:a');
    assert.equal(state.error, '');
    assert.equal(payload.real, true);
    assert.equal(payload.command, 'node');
    assert.equal(payload.nodeId, 'action:a');
});
