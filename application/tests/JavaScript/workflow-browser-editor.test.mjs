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
    collectionItemFields,
    filterItemFields,
    applyTriggerDisplayInput,
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
        collectionItemFields,
        filterItemFields,
        applyTriggerDisplayInput,
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

test('inspector rename updates immediately and saves only the node name in the background', async () => {
    const state = editor();
    state.selectedId = 'action:a';
    state.setDocument('name', 'Другой несохранённый заголовок');
    const calls = [];
    state.request = async (path, payload, method) => {
        calls.push({ path, payload, method });
        const saved = document();
        saved.definition.actions[0].name = payload.name;
        return { document: saved, version: 'new' };
    };
    state.renameCurrent('Быстрая пауза');
    assert.equal(state.currentNode.name, 'Быстрая пауза');
    assert.equal(calls.length, 0);
    await state.flushRename();
    assert.equal(calls.length, 1);
    assert.equal(calls[0].path, '/120/node-name');
    assert.equal(calls[0].method, 'PATCH');
    assert.equal(calls[0].payload.name, 'Быстрая пауза');
    assert.equal(state.version, 'new');
    assert.equal(state.doc.name, 'Другой несохранённый заголовок');
    assert(state.hasUnsavedChanges);
    state.destroy();
});

test('rapid renames serialize versions and keep the latest name', async () => {
    const state = editor();
    state.selectedId = 'action:a';
    let releaseFirst;
    const calls = [];
    state.request = (path, payload) => {
        calls.push(payload);
        const saved = document();
        saved.definition.actions[0].name = payload.name;
        if (calls.length === 1) return new Promise((resolve) => { releaseFirst = () => resolve({ document: saved, version: 'one' }); });
        return Promise.resolve({ document: saved, version: 'two' });
    };
    state.renameCurrent('Первая');
    const first = state.flushRename();
    state.renameCurrent('Вторая');
    const second = state.flushRename();
    await Promise.resolve();
    releaseFirst();
    await Promise.all([first, second]);
    assert.equal(calls[1].version, 'one');
    assert.equal(calls[1].name, 'Вторая');
    assert.equal(state.currentNode.name, 'Вторая');
    assert(!state.hasUnsavedChanges);
    state.destroy();
});

test('renaming a new node saves its graph automatically', async () => {
    const state = editor();
    state.selectedId = state.machine.add({ type: 'workflow_delay', name: 'Новая пауза', defaults: { seconds: 2 } });
    let path;
    state.request = async (requested, payload) => {
        path = requested;
        return { document: clone(payload.document), version: 'new' };
    };
    state.renameCurrent('Автоматически сохранена');
    await state.flushRename(true);
    assert.equal(path, '/120/document');
    assert(!state.hasUnsavedChanges);
    state.destroy();
});

test('list field suggestions use selected collection item keys without numeric expressions', () => {
    const state = editor();
    state.machine.value.definition.actions.push({ id: 'list', type: 'amocrm_read', name: 'Сделки', config: { operation: 'leads.list' } });
    state.machine.value.definition.actions.push({ id: 'filter', type: 'workflow_filter_list', name: 'Фильтр', config: { items: '{{ $("Сделки").items }}' } });
    state.machine.value.definition.connections.push(
        { sourceId: 'action:list', sourcePort: 'output', targetId: 'action:filter' },
    );
    state.selectedId = 'action:filter';
    state.canvasEdges = () => state.doc.definition.connections;
    state.debugState.results = [{ id: 'list', display_output: [{ id: 1, nested: { title: 'A' } }, { id: 2, price: 20 }] }];
    const sources = state.expressionSources();
    const keys = filterItemFields(sources, '{{ $("Сделки").items }}');
    assert.deepEqual(keys.sort(), ['id', 'nested', 'nested.title', 'price']);
    assert(!sources.flatMap((source) => source.fields).some((field) => /\[\d+\]/.test(field.expression)));
});

test('trigger input displays the entity while the execution keeps its original envelope', async () => {
    const state = editor();
    state.selectedId = 'trigger';
    const raw = { entity: 'lead', item: { id: 42, name: 'Заявка' }, account: { id: 7 }, source: 'amocrm' };
    const requests = [];
    state.request = async (path, payload) => {
        requests.push(payload);
        if (payload.command === 'input') return { input: raw, display_input: raw.item };
        return { session: 's', status: 'completed', results: [] };
    };
    state.setExecutionState = () => {};
    state.$refs.browserEditor = { toggleAttribute() {} };
    await state.loadDebugInput();
    assert.equal(requests[0].nodeId, 'trigger');
    assert.equal(state.debugInput, JSON.stringify(raw.item, null, 2));
    await state.runDebug('start');
    assert.equal(requests[1].input.account.id, 7);
    assert.equal(requests[1].input.item.id, 42);
    state.debugInput = JSON.stringify({ id: 42, name: 'Новая заявка' });
    await state.runDebug('start');
    assert.equal(requests[2].input.item.name, 'Новая заявка');
    assert.equal(requests[2].input.account.id, 7);
});

test('switching triggers discards the previous transport envelope', () => {
    const state = editor();
    state.machine.value.definition.additional_triggers = [{ id: 'trigger:hook', type: 'generic-webhook', name: 'Вебхук', config: {} }];
    state.debugRawInput = { source: 'amocrm', item: { id: 1 } };
    state.debugInput = '{"id":1}';
    state.debugSession = 'old';
    state.$refs.browserEditor = { toggleAttribute() {} };
    state.applyNodeSelection = () => {};
    state.openNode('trigger:hook');
    assert.equal(state.debugStartNodeId, 'trigger:hook');
    assert.equal(state.debugInput, '{}');
    assert.equal(state.debugRawInput, null);
    assert.equal(state.debugSession, null);
});

test('collection item paths and webhook edits never require a positional index', () => {
    assert.deepEqual(collectionItemFields([{ name: 'A', details: { city: 'Калининград' } }]), ['name', 'details', 'details.city']);
    const raw = { body: { account: { id: 5, subdomain: 'test' }, leads: { add: [{ id: 1 }] } }, headers: { Host: 'test' } };
    const edited = applyTriggerDisplayInput(raw, { id: 1, name: 'Новая' });
    assert.equal(edited.body.account.id, 5);
    assert.equal(edited.body.leads.add[0].name, 'Новая');
    assert.equal(edited.headers.Host, 'test');
    const normalized = applyTriggerDisplayInput({ entity: 'lead', action: 'add', item: { id: 1 }, lead: { id: 1 }, add: { id: 1 }, payload: { lead_id: 1 } }, { id: 2 });
    assert.equal(normalized.lead.id, 2);
    assert.equal(normalized.add.id, 2);
    assert.equal(normalized.payload.lead_id, 2);
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
