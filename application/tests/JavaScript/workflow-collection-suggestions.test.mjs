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
    relative.suggest({target: {value: 'contact.n', hasAttribute: () => true}});
    assert.deepEqual(Array.from(relative.suggestions, item => item.expression), ['contact.name']);
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
