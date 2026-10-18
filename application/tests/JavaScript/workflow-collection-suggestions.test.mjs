import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';

function fixture() {
    const window = {addEventListener() {}};
    runInNewContext(readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8'), {
        window, document: {addEventListener() {}},
    });
    const field = window.workflowValueField('');
    field.sources = [
        {id: 'fetch', name: 'Получить сделки', available: true, fields: [
            {key: '', type: 'array', expression: '{{ $("Получить сделки") }}', count: 2,
                item_fields: ['id', 'name', 'status_id', 'responsible_user_id', 'tags', 'contact', 'contact.name']},
        ]},
        {id: 'contacts', name: 'Контакты', fields: [
            {key: '', type: 'array', expression: '{{ $("Контакты") }}', item_fields: ['phone']},
        ]},
    ];
    const suggest = value => {
        field.suggest({target: {value, selectionStart: value.indexOf('|') >= 0 ? value.indexOf('|') : value.length, hasAttribute: () => true}});
        return Array.from(field.suggestions);
    };
    return {window, field, suggest};
}

test('a populated collection offers its body fields and whole list before generic functions', () => {
    const {suggest} = fixture();
    const choices = suggest('{{ $("Получить сделки").');
    assert.equal(choices[0].label, 'Весь список');
    assert.equal(choices[0].expression, '{{ $("Получить сделки") }}');
    assert.ok(choices.some(item => item.expression === '{{ $("Получить сделки").first().id }}'));
    assert.ok(choices.some(item => item.expression === '{{ $("Получить сделки").first().name }}'));
    assert.ok(choices.some(item => item.expression === '{{ $("Получить сделки").first().tags }}'));
    assert.ok(choices.every(item => item.source !== 'Контакты'));
    assert.ok(choices.every(item => !item.expression.includes('[0]')));
    assert.ok(choices.length <= 8);
});

test('typing a field prefix after the collection or first()/last() keeps actual fields discoverable', () => {
    const {suggest} = fixture();
    for (const [value, expected] of [
        ['{{ $("Получить сделки").na', '{{ $("Получить сделки").first().name }}'],
        ['{{ $("Получить сделки").responsible_u', '{{ $("Получить сделки").first().responsible_user_id }}'],
        ['{{ $("Получить сделки").first().sta', '{{ $("Получить сделки").first().status_id }}'],
        ['{{ $("Получить сделки").last().na', '{{ $("Получить сделки").last().name }}'],
        ["{{ $('Получить сделки').contact.na", '{{ $("Получить сделки").first().contact.name }}'],
    ]) {
        const choices = suggest(value);
        assert.ok(choices.some(item => item.expression === expected), value);
    }
});

test('nested lists and ordinary object fields remain separate and resolvable', () => {
    const {field, suggest} = fixture();
    field.sources = [{id: 'http', name: 'HTTP', fields: [
        {key: '', type: 'object', expression: '{{ $("HTTP") }}'},
        {key: '.result', type: 'array', expression: '{{ $("HTTP").result }}', item_fields: ['id', 'client_name']},
        {key: '.status', type: 'bool', expression: '{{ $("HTTP").status }}', label: 'status'},
    ]}];
    assert.ok(suggest('{{ $("HTTP").result.').some(item => item.expression === '{{ $("HTTP").result.first().client_name }}'));
    assert.ok(suggest('{{ $("HTTP").sta').some(item => item.expression === '{{ $("HTTP").status }}'));
});

test('explicit first/last/cf prefixes keep functions reachable with many body fields', () => {
    const {suggest} = fixture();
    for (const [prefix, suffix] of [['fi', 'first()'], ['la', 'last()'], ['c', 'cf()']]) {
        const choices = suggest('{{ $("Получить сделки").' + prefix);
        assert.equal(choices[0].expression, '{{ $("Получить сделки").' + suffix + ' }}');
    }
});

test('choosing a body field or whole collection replaces only the current token', () => {
    const {field, suggest} = fixture();
    let value = 'До {{ $("Получить сделки").na }} после';
    const input = {value, selectionStart: value.indexOf(' }}'), hasAttribute: () => true};
    field.suggest({target: input});
    field.$refs = {value: input}; field.$nextTick = () => {};
    field.chooseSuggestion(field.suggestions[0]);
    assert.equal(field.state, 'До {{ $("Получить сделки").first().name }} после');
    value = '{{ $("Получить сделки"). }}';
    input.value = value; input.selectionStart = value.indexOf(' }}');
    field.suggest({target: input});
    field.chooseSuggestion(field.suggestions[0]);
    assert.equal(field.state, '{{ $("Получить сделки") }}');
});

test('relative filter keys stay relative; typing never queries the server', () => {
    const {window, field} = fixture();
    const relative = window.workflowValueField('', 'mountedActions.0.data.items');
    relative.sources = field.sources;
    relative.$wire = {$get: () => '{{ $("Получить сделки") }}', $call() {assert.fail('Typing must stay local');}};
    relative.suggest({target: {value: 'contact', hasAttribute: () => true}});
    assert.deepEqual(Array.from(relative.suggestions, item => item.expression), []);
    relative.suggest({target: {value: 'sta', hasAttribute: () => true}});
    assert.deepEqual(Array.from(relative.suggestions, item => item.expression), ['status_id']);
    assert.ok(relative.suggestions.every(item => !item.expression.includes('first()')));
});

test('an empty or unknown item schema keeps whole-list and function completion without inventing fields', () => {
    const {field, suggest} = fixture();
    field.sources[0].fields[0].item_fields = [];
    const choices = suggest('{{ $("Получить сделки").');
    assert.equal(choices[0].expression, '{{ $("Получить сделки") }}');
    assert.ok(choices.some(item => item.expression === '{{ $("Получить сделки").first() }}'));
    assert.ok(!choices.some(item => item.expression.endsWith('.name }}')));
});

test('filter collection completion addresses each item directly and stops at its first level', () => {
    const {window, field} = fixture();
    const filter = window.workflowValueField('', null, 'mountedActions.0.data.rules');
    filter.sources = field.sources;
    const suggest = value => {
        filter.suggest({target: {value, selectionStart: value.length, hasAttribute: () => true}});
        return Array.from(filter.suggestions);
    };
    const choices = suggest('{{ $("Получить сделки").');
    assert.ok(choices.some(item => item.expression === '{{ $("Получить сделки").id }}'));
    assert.ok(choices.some(item => item.expression === '{{ $("Получить сделки").contact }}'));
    assert.ok(choices.every(item => !/first\(|last\(|cf\(|contact\.name|\[0\]/.test(item.expression)));
    assert.equal(suggest('{{ $("Получить сделки").contact.').length, 0);
    assert.equal(suggest('{{ $("Получить сделки").first().').length, 0);
    assert.equal(suggest("{{ $('Получить сделки').sta")[0].expression, '{{ $("Получить сделки").status_id }}');
});

test('choosing an item key keeps the complete collection and fills only an empty rule locally', () => {
    const {window, field} = fixture();
    const filter = window.workflowValueField('', null, 'mountedActions.0.data.rules');
    filter.sources = field.sources;
    const value = '{{ $("Получить сделки").sta }}';
    const input = {value, selectionStart: value.indexOf(' }}'), hasAttribute: () => true};
    const writes = [];
    filter.$wire = {
        $get: () => ({existing: {field: 'name', operator: 'eq', value: 'Не менять'}, fresh: {field: '', operator: 'eq', value: '142'}}),
        $set: (path, value, live) => writes.push([path, value, live]),
        $call() { assert.fail('Selecting an item key must stay in the browser'); },
    };
    filter.$refs = {value: input}; filter.$nextTick = () => {};
    filter.suggest({target: input});
    filter.chooseSuggestion(filter.suggestions[0]);
    assert.equal(filter.state, '{{ $("Получить сделки") }}');
    assert.deepEqual(writes, [
        ['mountedActions.0.data.__filter_item_field', 'status_id', false],
        ['mountedActions.0.data.rules.fresh.field', 'status_id', false],
    ]);
});

test('JSON list key completion excludes nested paths, indices and invalid property names', () => {
    const {window} = fixture();
    assert.deepEqual(Array.from(window.workflowFilterItemFields([], '[{"id":1,"contact":{"name":"Тест"},"contact.name":1,"0":1,"Имя":"Тест"}]')), ['id', 'contact', 'Имя']);
    assert.equal(window.workflowFilterItemPath('{{ $("Сделки").contact.name }}', '{{ $("Сделки") }}'), '{{ $("Сделки").contact.name }}');
});
