import test from 'node:test';
import assert from 'node:assert/strict';
import {compactPickerFields, compactPickerPosition} from '../../resources/js/workflow-compact-field-picker.mjs';

const source = {
    id: 'contact-find', name: 'Получить контакты',
    fields: [
        {label: 'name', key: '.name', expression: '{{ $("Получить контакты").name }}', available: false},
        {label: 'author', key: '.author', expression: '{{ $("Получить контакты").author }}'},
        {label: 'name', key: '.author.name', expression: '{{ $("Получить контакты").author.name }}'},
        {label: 'attachment', key: '.attachment', expression: null},
    ],
};
test('compact picker searches localized labels and nested paths without needing recorded values', () => {
    assert.deepEqual(compactPickerFields(source, 'иМя').map(item => item.key), ['.name', '.author.name']);
    assert.equal(compactPickerFields(source, 'author name')[0].expression, source.fields[2].expression);
    assert.deepEqual(compactPickerFields(source, 'attachment'), []);
});

test('compact picker keeps collections intact and uses existing first-item syntax for item paths', () => {
    const array = {
        fields: [{label:'Результат', key:'', type:'array', expression:'{{ $("Контакты") }}', item_fields:['id','author.name','bad()']}],
    };
    assert.deepEqual(compactPickerFields(array).map(item => item.expression), [
        '{{ $("Контакты") }}', '{{ $("Контакты").first().id }}', '{{ $("Контакты").first().author.name }}',
    ]);
    assert.deepEqual(compactPickerFields(array, '', true).map(item => item.expression), ['{{ $("Контакты") }}']);
});

test('popup aligns beside the inspector, flips at edges, and fits narrow viewports', () => {
    const size = {width:295, height:229};
    const screen = {width:1280,height:720};
    assert.deepEqual(compactPickerPosition({left:700,right:1128},{top:470,bottom:504,right:1112},screen,size), {left:397,top:380});
    assert.deepEqual(compactPickerPosition({left:32,right:460},{top:200,bottom:234,right:444},screen,size), {left:468,top:110});
    assert.deepEqual(compactPickerPosition({left:8,right:352},{top:520,bottom:554,right:336},{width:360,height:640},size), {left:41,top:283});
});
