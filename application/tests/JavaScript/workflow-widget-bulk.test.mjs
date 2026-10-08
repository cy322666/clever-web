import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const source = readFileSync(new URL('../../public/amocrm/workflows/manual-buttons/script.js', import.meta.url), 'utf8');
const manifest = JSON.parse(readFileSync(new URL('../../public/amocrm/workflows/manual-buttons/manifest.json', import.meta.url), 'utf8'));
const plain = value => JSON.parse(JSON.stringify(value));

// Execute the actual AMD widget and its SDK callbacks without a live CRM or requests.
function widget(selected, area = 'clist', pathname = '/contacts/list/') {
    const elements = new Map();
    const handlers = new Map();
    const requests = [];
    function $(selector = '') {
        if (selector?.mockElement) return selector;
        if (elements.has(selector)) return elements.get(selector);
        const element = {
            mockElement: true, length: 0, values: {}, content: '',
            data(key, value) { if (arguments.length === 1) return this.values[key]; this.values[key] = value; return this; },
            remove() { this.values = {}; return this; },
            append(html) { this.content += html; return this; },
            html(html) { this.content = html; return this; },
            text(text) { this.content = text; return this; },
            each() { return this; }, not() { return this; }, off() { return this; },
            prop() { return this; }, toArray() { return []; },
            find(child) { return $(String(selector) + ' ' + child); },
            on(event, selector, handler) { if (handler) handlers.set(selector, handler); return this; },
        };
        elements.set(selector, element);
        return element;
    }
    $.each = (items, callback) => Object.entries(items).forEach(([key, value]) => callback(key, value));
    $.extend = Object.assign;
    $.param = data => new URLSearchParams(data).toString();
    let Widget;
    runInNewContext(source, {
        define(deps, factory) { Widget = factory($); },
        APP: { constant: () => ({ subdomain: 'test-account' }) },
        window: { location: { pathname }, setTimeout() {}, clearTimeout() {} }, document: {},
    });
    const instance = new Widget();
    instance.system = () => ({ area });
    instance.list_selected = () => ({ selected });
    instance.crm_post = (url, data, success) => {
        requests.push({ url, data: plain(data), success });
        if (!url.endsWith('/bulk-run')) success({ ok: true, workflows: [{ id: 7, name: 'Тест' }] });
    };
    instance.callbacks.bind_actions();
    const button = $('test-button').data('workflow-id', 7);
    return {
        instance, requests,
        clickRun() { handlers.get('.clever-workflow-bulk__scenario').call(button); },
        modal: () => $('#clever-workflow-bulk-modal'),
        message: () => $('#clever-workflow-bulk-modal .clever-workflow-bulk__content').content,
    };
}

test('package supports the SDK deal and contact/company list locations', () => {
    assert.ok(manifest.locations.includes('llist-0'));
    assert.ok(manifest.locations.includes('clist-0'));
    assert.equal(manifest.widget.version, '1.0.46');
    assert.ok(source.includes("var VERSION = '" + manifest.widget.version + "'"));
});

for (const [callback, type, area, pathname] of [
    ['leads', 'lead', 'llist', '/leads/list/'],
    ['contacts', 'contact', 'clist', '/contacts/list/'],
    ['contacts', 'company', 'clist', '/companies/list/'],
    ['companies', 'company', 'clist', '/companies/list/'],
]) {
    test(`${callback} callback lists and runs only mass-action workflows for ${type}`, () => {
        const app = widget([{ id: 42, type, phones: ['79991234567'], emails: ['test@example.test'] }], area, pathname);
        app.instance.callbacks[callback].selected();
        assert.equal(new URL(app.requests[0].url).searchParams.get('source'), 'amo-bulk');
        app.clickRun();
        assert.equal(app.requests[1].url.endsWith('/bulk-run'), true);
        assert.deepEqual(app.requests[1].data, {
            subdomain: 'test-account', workflow_id: 7, source: 'amo-bulk', entities: [{ type, id: 42 }],
        });
        app.clickRun();
        assert.equal(app.requests.length, 2, 'double click must not enqueue twice');
    });
}

test('mixed selection deduplicates by type and ID without treating indexes or phone numbers as IDs', () => {
    const app = widget([
        { id: 42, type: 'contact', phones: ['79112223344'] },
        { id: 42, type: 'company' }, { id: 42, type: 'contacts' },
    ]);
    app.instance.callbacks.contacts.selected();
    app.clickRun();
    assert.deepEqual(app.requests[1].data.entities, [{ type: 'contact', id: 42 }, { type: 'company', id: 42 }]);
});

test('SDK keyed selection uses record IDs, not object keys', () => {
    const app = widget({ 0: { id: 42, type: 'lead' }, 1: { id: 55, type: 'lead' } }, 'llist', '/leads/list/');
    app.instance.callbacks.leads.selected();
    app.clickRun();
    assert.deepEqual(app.requests[1].data.entities, [{ type: 'lead', id: 42 }, { type: 'lead', id: 55 }]);
});

test('unknown or missing types in mixed contacts lists fail closed', () => {
    for (const selected of [[{ id: 42 }], [{ id: 42, type: 'task' }], [{ id: '42abc', type: 'contact' }]]) {
        const app = widget(selected);
        app.instance.callbacks.contacts.selected();
        assert.equal(app.requests.length, 0);
        assert.match(app.message(), /Не удалось определить тип/);
    }
});

test('empty and oversized selections never make requests', () => {
    for (const selected of [[], Array.from({ length: 251 }, (_, i) => ({ id: i + 1, type: 'contact' }))]) {
        const app = widget(selected);
        app.instance.callbacks.contacts.selected();
        assert.equal(app.requests.length, 0);
        assert.match(app.message(), /от 1 до 250/);
    }
});
