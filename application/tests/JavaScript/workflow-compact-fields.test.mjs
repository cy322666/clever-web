import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';
import test from 'node:test';

const plain = value => JSON.parse(JSON.stringify(value));
function components(writeText = async () => {}, windowOverrides = {}, documentOverrides = {}) {
    const window = {addEventListener() {}, setTimeout() {}, clearTimeout() {}, ...windowOverrides};
    runInNewContext(readFileSync(new URL('../../resources/js/workflows.js', import.meta.url), 'utf8'), {
        window, document:{addEventListener() {},...documentOverrides}, navigator:{clipboard:{writeText}},
    });
    return window;
}
function headers(state) {
    const field = components().workflowHeadersField(state);
    field.$watch = () => {};
    field.init();
    return field;
}

test('bounded numeric inputs clamp typed and pasted values while preserving expressions and empty input', () => {
    for (const [value,expected] of [['-2','0'],['16','15'],['99','15'],['0','0'],['15','15'],['8','8'],['','']]) {
        const field=components().workflowValueField(value);
        const input={type:'number',value,min:'0',max:'15'};
        field.clampNumber({target:input});
        assert.equal(input.value,expected);
        assert.equal(field.state,expected);
    }
    const field=components().workflowValueField('{{ $json.seconds }}');
    field.mode='expression';
    field.clampNumber({target:{type:'text',value:field.state,min:'0',max:'15'}});
    assert.equal(field.state,'{{ $json.seconds }}');
});

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
        assert.match(source,/copyValue\(row\.(?:item\.)?value, row.id, \$event.currentTarget\)/);
        assert.match(source,/@include\('filament.workflow-builder.workflow-copy-feedback'\)/);
    }
    const picker=readFileSync(new URL('../../resources/views/filament/workflow-builder/workflow-expression-picker.blade.php',import.meta.url),'utf8');
    assert.match(picker,/application\/workflow-expression/);
    assert.doesNotMatch(picker,/x-on:click="insert\(row.item\)"/);
});

test('copy feedback appears only after clipboard success, stays near the value and expires', async () => {
    let resolveCopy, expire;
    const cleared = [];
    const viewer = components(() => new Promise(resolve => { resolveCopy = resolve; }), {
        innerWidth: 800, innerHeight: 600,
        setTimeout(callback, delay) { assert.equal(delay, 1500); expire = callback; return 7; },
        clearTimeout(timer) { cleared.push(timer); },
    }).workflowJsonViewer({});
    const anchor = {
        isConnected: true, getClientRects: () => [{}],
        getBoundingClientRect: () => ({left: 750, top: 10, bottom: 28}),
    };
    const pending = viewer.copyValue(42, 'row', anchor);
    assert.equal(viewer.copyPosition, null);
    assert.equal(viewer.copiedRow, null);
    resolveCopy(); await pending;
    assert.deepEqual(plain(viewer.copyPosition), {left: 674, top: 36});
    assert.equal(viewer.copiedRow, 'row');
    expire();
    assert.equal(viewer.copiedRow, null);
    assert.equal(viewer.copyPosition, null);
    assert.ok(cleared.includes(7));

    anchor.getBoundingClientRect = () => ({left: 100, top: 300, bottom: 318});
    const again = viewer.copyValue(0, 'next', anchor);
    resolveCopy(); await again;
    assert.deepEqual(plain(viewer.copyPosition), {left: 100, top: 266});
    viewer.destroy();
    assert.equal(viewer.copyPosition, null);
    assert.equal(viewer.copyTimer, null);
});

test('late clipboard replies cannot revive a notice after another click or component removal', async () => {
    const pending = [];
    const viewer = components(() => new Promise((resolve, reject) => pending.push({resolve, reject}))).workflowJsonViewer({});
    const first = viewer.copyValue(1, 'first'), second = viewer.copyValue(2, 'second');
    pending[1].resolve(); await second;
    pending[0].resolve(); await first;
    assert.equal(viewer.copiedRow, 'second');

    const failed = viewer.copyValue(3, 'failed'), last = viewer.copyValue(4, 'last');
    pending[3].resolve(); await last;
    pending[2].reject(Error('denied')); await failed;
    assert.equal(viewer.copiedRow, 'last');
    assert.equal(viewer.copyError, '');

    const removed = viewer.copyValue(5, 'removed');
    viewer.destroy();
    pending[4].resolve(); await removed;
    assert.equal(viewer.copiedRow, null);
    assert.equal(viewer.copyPosition, null);
});

test('hidden values and clipboard failures never display success feedback', async () => {
    for (const anchor of [{isConnected: false}, {isConnected: true, getClientRects: () => []}]) {
        const viewer = components().workflowJsonViewer({});
        await viewer.copyValue(42, 'hidden', anchor);
        assert.equal(viewer.copiedRow, null);
        assert.equal(viewer.copyPosition, null);
    }
    const failed = components(async () => { throw Error('denied'); }).workflowJsonViewer({});
    await failed.copyValue(42, 'failed', {});
    assert.equal(failed.copyPosition, null);
    assert.equal(failed.copiedRow, null);
    assert.match(failed.copyError, /Не удалось/);
});

test('shared copy notice is accessible, unclipped and dismisses when navigating away', () => {
    const view = readFileSync(new URL('../../resources/views/filament/workflow-builder/workflow-copy-feedback.blade.php', import.meta.url), 'utf8');
    assert.match(view, /x-teleport="body"/);
    assert.match(view, /role="status" aria-live="polite"/);
    assert.match(view, /x-show="copiedRow !== null && copyPosition !== null"/);
    for (const event of ['pointerdown.window', 'keydown.window', 'scroll.window.capture', 'resize.window']) {
        assert.ok(view.includes(`x-on:${event}="clearCopyFeedback()"`));
    }
    const css = readFileSync(new URL('../../resources/css/workflow-inspector.css', import.meta.url), 'utf8');
    const notice = css.match(/\.workflow-copy-feedback \{([^}]+)\}/)[1];
    assert.match(notice, /position: fixed/);
    assert.match(notice, /pointer-events: none/);
    assert.match(notice, /width: 118px; height: 26px/);
    assert.match(view, /Скопировано/);
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

test('native multiple choice preserves saved IDs, prevents duplicates and removes only the chosen employee', () => {
    const original=[9, '17', '999'];
    const field=components().workflowValueField(original,null,null,{9:'Яна',17:'Support',21:'Лера'});
    field.init();
    assert.equal(field.state,original,'Opening the dropdown must not rewrite saved IDs');
    field.addSelectedOption('9');field.addSelectedOption('');
    assert.equal(field.state,original);
    field.addSelectedOption('21');
    assert.deepEqual(plain(field.state),[9,'17','999','21']);
    field.removeSelectedOption(17);
    assert.deepEqual(plain(field.state),[9,'999','21']);
    assert.deepEqual(original,[9,'17','999']);
    const empty=components().workflowValueField(null);
    empty.addSelectedOption('9');
    assert.deepEqual(plain(empty.state),['9']);
});


test('autocomplete placement uses viewport space and flips above near the bottom',()=>{
    const place=components().workflowSuggestionPlacement;
    assert.deepEqual(plain(place({left:100,top:120,bottom:152,width:300},{width:800,height:600},180)),{left:100,top:156,width:300,maxHeight:180});
    const bottom=place({left:730,top:540,bottom:572,width:200},{width:800,height:600},230);
    assert.equal(bottom.left,592);
    assert.equal(bottom.top,306);
    assert.equal(bottom.maxHeight,230);
    const small=place({left:40,top:80,bottom:112,width:320},{width:200,height:160},230);
    assert.equal(small.width,184);
    assert.equal(small.left,8);
    assert.equal(small.top,8);
    assert.equal(small.maxHeight,68);
});

test('suggestions extend outside a short form and close when their input scrolls out',()=>{
    const field=components(undefined,{}, {documentElement:{clientWidth:800,clientHeight:600}}).workflowValueField('');
    let bounds={left:100,top:150,bottom:182,width:300,height:32};
    field.suggestions=[{expression:'{{ $json.id }}'}];
    field.$refs={value:{isConnected:true,getBoundingClientRect:()=>bounds,closest:()=>({getBoundingClientRect:()=>({top:100,bottom:200})})},suggestionList:{scrollHeight:140}};
    field.positionSuggestions();
    assert.equal(field.suggestionPosition.top,'186px');
    assert.equal(field.suggestionPosition.maxHeight,'140px');
    assert.equal(field.suggestionPosition.visibility,'visible');
    bounds={...bounds,top:40,bottom:72};
    field.positionSuggestions();
    assert.equal(field.suggestions.length,0);
    assert.equal(field.suggestionPosition.visibility,'hidden');
});
