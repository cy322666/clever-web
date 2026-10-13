import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';
import test from 'node:test';

const plain = value => JSON.parse(JSON.stringify(value));
function components(writeText = async () => {}) {
    const window = {addEventListener() {}, setTimeout() {}, clearTimeout() {}};
    runInNewContext(readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8'), {
        window, document:{addEventListener() {}}, navigator:{clipboard:{writeText}},
    });
    return window;
}
function headers(state) {
    const field = components().workflowHeadersField(state);
    field.$watch = () => {};
    field.init();
    return field;
}

test('headers open saved JSON or objects as name/value rows without changing the saved value', () => {
    for (const state of ['{"ACCESS_TOKEN":"test","X-Zero":0,"X-Bool":false}', {ACCESS_TOKEN:'test','X-Zero':0,'X-Bool':false}]) {
        const field = headers(state);
        assert.equal(field.state,state);
        assert.equal(field.mode,'fields');
        assert.deepEqual(plain(field.rows.map(({name,value})=>({name,value}))),[
            {name:'ACCESS_TOKEN',value:'test'},{name:'X-Zero',value:0},{name:'X-Bool',value:false},
        ]);
        field.rows[0].value = '{{ $json.token }}'; field.syncRows();
        field.setMode('json');
        assert.deepEqual(JSON.parse(field.raw), {ACCESS_TOKEN:'{{ $json.token }}','X-Zero':0,'X-Bool':false});
        field.setMode('fields');
        assert.equal(field.rows[0].value,'{{ $json.token }}');
    }
});

test('headers add/remove and mode switches preserve invalid rows for validation instead of dropping them', () => {
    const field = headers('{}');
    field.addRow(); field.rows[0].name='X-Test'; field.rows[0].value='a'; field.syncRows();
    field.addRow(); field.rows[1].name='X-Test'; field.rows[1].value='b'; field.syncRows();
    field.setMode('json'); assert.equal(JSON.parse(field.raw).length,2);
    field.setMode('fields'); assert.equal(field.rows.length,2);
    field.removeRow(field.rows[0].id); assert.equal(field.state[0].value,'b');
    field.removeRow(field.rows[0].id); assert.deepEqual(plain(field.state),[]);
    for (const value of ['{{ $json.headers }}','{bad','{"X-Test":{"nested":1}}']) {
        const other = headers(value);
        assert.equal(other.mode,'json'); assert.equal(other.state,value);
        other.setMode('fields'); assert.equal(other.mode,'json'); assert.ok(other.error);
        assert.equal(other.state,value);
    }
});

test('headers refresh from server state and JSON edits are not overwritten by their own watch', () => {
    const field = headers('{}');
    field.raw = '{"Accept":"application/json"}'; field.syncJson(); field.setMode('fields');
    assert.equal(field.rows[0].name,'Accept');
    field.state = [{name:'X-New',value:'new'}]; field.loadState();
    assert.equal(field.rows[0].name,'X-New');
    field.rows[0].value='changed'; field.syncRows();
    assert.equal(field.signature,JSON.stringify(field.state));
});

test('both data viewers copy literal values, including false, zero, null and strings without JSON punctuation', async () => {
    const copied=[];
    const factories=components(async value=>copied.push(value));
    for (const viewer of [factories.workflowJsonViewer({}),factories.workflowExpressionPicker([])]) {
        for (const value of ['текст "в кавычках"',0,false,null,'']) {
            await viewer.copyValue(value,'row');
            assert.equal(viewer.copiedRow,'row'); assert.equal(viewer.copyError,'');
        }
    }
    assert.deepEqual(copied,Array(2).fill(['текст "в кавычках"','0','false','null','']).flat());
    const failed=components(async()=>{throw Error('denied');}).workflowJsonViewer(null);
    await failed.copyValue(1,'one');
    assert.equal(failed.copiedRow,null); assert.match(failed.copyError,/Не удалось/);
});

test('JSON row content handles folding and copying; dragging still carries the expression', () => {
    const window=components(), viewer=window.workflowJsonViewer({items:[{id:42}]});
    viewer.init();
    assert.equal(viewer.rows.find(row=>row.path==='$.items').collapsed,true);
    viewer.toggle('$.items'); viewer.toggle('$.items.0');
    assert.equal(viewer.rows.find(row=>row.path==='$.items.0.id').value,42);
    assert.ok(viewer.rows.filter(row=>row.closing).every(row=>!row.branch));
    for (const view of ['workflow-json-tree','workflow-expression-picker']) {
        const source=readFileSync(new URL(`../../resources/views/filament/workflow-builder/${view}.blade.php`,import.meta.url),'utf8');
        assert.doesNotMatch(source,/collapseAll|expandAll|class="workflow-input-json__fold"/);
        assert.match(source,/copyValue\(row\.(?:item\.)?value, row.id\)/);
    }
    const picker=readFileSync(new URL('../../resources/views/filament/workflow-builder/workflow-expression-picker.blade.php',import.meta.url),'utf8');
    assert.match(picker,/application\/workflow-expression/);
    assert.doesNotMatch(picker,/x-on:click="insert\(row.item\)"/);
});

test('picker copies empty collections but never copies stripped container data as null', () => {
    const window = components();
    for (const [type, count, available, expected] of [['array',0,true,[]],['object',0,true,{}],['object',2,true,null],['array',0,false,null]]) {
        const picker = window.workflowExpressionPicker([{id:'one',available,fields:[{key:'',parent:null,type,count,available,value:null}]}]);
        const row = picker.jsonRows[0];
        assert.equal(row.copyable,expected !== null);
        if (row.copyable) assert.deepEqual(plain(row.value),expected);
    }
});
