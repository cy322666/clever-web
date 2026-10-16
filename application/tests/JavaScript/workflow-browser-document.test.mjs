import test from 'node:test';
import assert from 'node:assert/strict';
import {
    BrowserWorkflowDocument,
    remapReferences,
    getPath,
    setPath,
} from '../../resources/js/workflow-browser-document.mjs';
import { nodeFields } from '../../resources/js/workflow-browser-fields.mjs';

const fixture = () => ({
    id: 120,
    name: 'Заказы',
    is_active: false,
    failure_strategy: 'stop',
    definition: {
        trigger: { type: 'manual', name: 'Старт', config: [] },
        actions: [
            {
                id: 'loop',
                type: 'workflow_loop',
                name: 'Каждый заказ',
                config: { items: '{{ $("Запрос").result }}', mode: 'all', require_return: true },
            },
            {
                id: 'http',
                type: 'http_request',
                name: 'Запрос',
                config: {
                    url: 'https://example.test',
                    headers: '{"Authorization":"private-test-key"}',
                    body: '{"id":"{{ $(\\"Каждый заказ\\").id }}"}',
                    custom_key: { keep: true },
                },
            },
            {
                id: 'condition',
                type: 'control-condition',
                name: 'Оплачен?',
                config: {
                    logic: 'and',
                    conditions: [{ left: '{{ $("Каждый заказ").status }}', operator: 'equals', right: '1' }],
                },
            },
        ],
        connections: [
            { sourceId: 'trigger', sourcePort: 'output', targetId: 'action:http' },
            { sourceId: 'action:http', sourcePort: 'output', targetId: 'action:loop' },
            { sourceId: 'action:loop', sourcePort: 'each', targetId: 'action:condition' },
            { sourceId: 'action:condition', sourcePort: 'yes', targetId: 'action:loop' },
        ],
    },
});

test('ordinary local editing makes no fetch calls and preserves unknown config/keys', () => {
    const document = new BrowserWorkflowDocument(fixture());
    const original = globalThis.fetch;
    globalThis.fetch = () => assert.fail('Editing must not access the server');
    try {
        document.rename('action:loop', 'Заказ');
        const copy = document.value.definition.actions.find((node) => node.id === 'http');
        assert.equal(copy.config.headers, '{"Authorization":"private-test-key"}');
        assert.deepEqual(copy.config.custom_key, { keep: true });
        assert.equal(copy.config.body, '{"id":"{{ $(\\"Заказ\\").id }}"}');
        assert.equal(document.node('action:condition').config.conditions[0].left, '{{ $("Заказ").status }}');
        const id = document.add(
            { type: 'workflow_delay', name: 'Пауза', defaults: { seconds: 1 } },
            { connection: { sourceId: 'action:http', sourcePort: 'output', targetId: 'action:loop' } },
        );
        assert(
            document.value.definition.connections.some(
                (edge) => edge.sourceId === id && edge.targetId === 'action:loop',
            ),
        );
        document.remove(id);
        assert(
            document.value.definition.connections.some(
                (edge) => edge.sourceId === 'action:http' && edge.targetId === 'action:loop',
            ),
        );
        assert(document.dirty);
    } finally {
        globalThis.fetch = original;
    }
});

test('empty PHP config dictionaries become editable objects instead of arrays losing properties', () => {
    const document = new BrowserWorkflowDocument(fixture());
    document.node('trigger').config.timezone = 'Europe/Moscow';
    assert.equal(
        JSON.parse(JSON.stringify(document.value)).definition.trigger.config.timezone,
        'Europe/Moscow',
    );
    const id = document.add({ type: 'custom', name: 'Тест', defaults: [] });
    document.node(id).config.foo = 'bar';
    assert.equal(JSON.parse(JSON.stringify(document.value)).definition.actions.at(-1).config.foo, 'bar');
});

test('node reference rename preserves literals and handles escaped JSON, both quote styles', () => {
    assert.equal(
        remapReferences('Запрос; {{ $("Запрос").id }}; {{ $(\'Запрос\') }}', { Запрос: 'Новый' }),
        'Запрос; {{ $("Новый").id }}; {{ $(\'Новый\') }}',
    );
    assert.equal(remapReferences('$("Запрос")', { Запрос: 'Новый' }), '$("Запрос")');
    assert.equal(remapReferences('$("Запросы")', { Запрос: 'Новый' }), '$("Запросы")');
    assert.throws(() => new BrowserWorkflowDocument(fixture()).rename('action:loop', 'Запрос'), /уже есть/);
});

test('rename preserves arbitrary JavaScript and updates legacy node expressions including quoted names', () => {
    const value = { javascript_code: 'return \'{{ $("Запрос") }}\';', json: '{{ $node["Запрос"].json.id }}' };
    assert.equal(remapReferences(value, { Запрос: 'Новый' }).javascript_code, value.javascript_code);
    assert.equal(remapReferences(value, { Запрос: 'Новый' }).json, '{{ $node["Новый"].json.id }}');
    const old = 'Имя "с кавычками"';
    assert.equal(
        remapReferences('{{ $(' + JSON.stringify(old) + ').id }}', { [old]: 'Новое' }),
        '{{ $("Новое").id }}',
    );
});

test('copy keeps internal edges, unique names and copied references without touching originals', () => {
    const document = new BrowserWorkflowDocument(fixture());
    document.copy(['action:loop', 'action:condition']);
    const mapping = document.paste();
    assert.equal(Object.keys(mapping).length, 2);
    assert.equal(document.node(mapping['action:loop']).name, 'Каждый заказ (2)');
    assert.equal(
        document.node(mapping['action:condition']).config.conditions[0].left,
        '{{ $("Каждый заказ (2)").status }}',
    );
    assert.equal(
        document.node('action:condition').config.conditions[0].left,
        '{{ $("Каждый заказ").status }}',
    );
    assert(
        document.value.definition.connections.some(
            (edge) =>
                edge.sourceId === mapping['action:condition'] && edge.targetId === mapping['action:loop'],
        ),
    );
});

test('conditions are not bridged on deletion, loop edges and detached children survive', () => {
    const document = new BrowserWorkflowDocument(fixture());
    document.remove('action:condition');
    assert(
        !document.value.definition.connections.some(
            (edge) => edge.sourceId === 'action:loop' && edge.targetId === 'action:loop',
        ),
    );
    assert(document.node('action:loop'));
});

test('undo/redo restores graph and state; saved baseline is independent of history', () => {
    const document = new BrowserWorkflowDocument(fixture());
    document.rename('action:loop', 'Заказ');
    assert(document.undo());
    assert.equal(document.node('action:loop').name, 'Каждый заказ');
    assert.equal(document.dirty, false);
    assert(document.undo(true));
    assert.equal(document.node('action:loop').name, 'Заказ');
    assert(document.dirty);
});

test('connection operations validate ports and incompatible branches without changing valid graph', () => {
    const document = new BrowserWorkflowDocument(fixture());
    assert.throws(() => document.connect('action:http', 'yes', 'action:condition'), /Недопустимая/);
    assert.throws(() => document.connect('action:http', 'output', 'action:http'), /с собой/);
    document.connect('action:condition', 'yes', 'action:http');
    assert.throws(() => document.connect('action:condition', 'no', 'action:http'), /Ветки/);
    document.connect('action:condition', 'no', 'action:loop');
    document.disconnect('action:condition', 'no', 'action:loop');
    assert(!document.value.definition.connections.some((edge) => edge.sourcePort === 'no'));
});

test('safe paths reject prototype pollution and handle nested repeater state', () => {
    const object = {};
    setPath(object, 'conditions.0.left', 'abc');
    assert.equal(getPath(object, 'conditions.0.left'), 'abc');
    assert.throws(() => setPath(object, '__proto__.polluted', true));
    assert.equal({}.polluted, undefined);
});

test('condition controls, loop limit and contact source are local field descriptions', () => {
    const condition = nodeFields({ type: 'control-condition', config: { logic: 'and', conditions: [] } });
    assert(condition[0].buttons);
    assert.deepEqual(condition[0].options, { and: 'И', or: 'ИЛИ' });
    assert.equal(condition[1].fields[0].label, '');
    const loop = nodeFields({ type: 'workflow_loop', config: { mode: 'limit' } });
    assert(loop.find((field) => field.path === 'limit').visible());
    const contact = nodeFields({ type: 'amocrm_contact_leads', config: { source: 'contact' } });
    assert(contact[0].buttons);
    assert(contact[1].visible());
    assert(!contact[2].visible());
});

test('read operation modes and with presets retain one shared config value', () => {
    const fields = nodeFields(
        { type: 'amocrm_read', config: { operation: 'leads.one' } },
        {},
        {
            'leads.one': {
                path: '/api/v4/leads/{id}',
                variants: { 'leads.list': 'Список', 'leads.one': 'По ID' },
                with: { contacts: 'Контакты' },
            },
        },
    );
    assert(fields.some((field) => field.path === 'id'));
    assert.equal(fields.find((field) => field.path === 'with_preset').options.contacts, 'Контакты');
});
