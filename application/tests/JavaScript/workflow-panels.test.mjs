import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';
import test from 'node:test';

function components() {
    const window = {addEventListener() {}};
    runInNewContext(readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8'), {
        window, document: {addEventListener() {}},
    });
    return window;
}

test('expression coloring marks only placeholders and treats markup as plain text', () => {
    const parts = components().workflowExpressionParts('Привет {{ $node["Сделки"].json.id }} <b>текст</b> {{lead.id}}');
    assert.equal(parts.filter(p=>p.variable).length,2);
    assert.equal(parts[0].variable,false);
    assert.equal(parts[2].text,' <b>текст</b> ');
    assert.equal(components().workflowExpressionParts('ещё {{не закрыто')[0].variable,false);
});

test('boolean option values use numeric keys without an empty false option', () => {
    const off=components().workflowValueField(false); off.init(); assert.equal(off.state,'0');
    const on=components().workflowValueField(true); on.init(); assert.equal(on.state,'1');
});

test('history shows captured HTTP exchanges and preserves the selected step on refresh', () => {
    const exchange = {request:{method:'POST',body:[{text:'Привет'}]},response:{code:200,body:{id:42}}};
    const a = {id:'a',type:'amocrm_add_note',output:{amo_exchange:[exchange]}};
    const b = {id:'b',type:'workflow_delay',output:{seconds:5}};
    const viewer = components().workflowExecutionViewer({results:[a,b],nodes:[]});
    assert.equal(viewer.requestData(),exchange.request.body);
    assert.equal(viewer.responseData(),exchange.response.body);
    viewer.selectNode('action:b');
    viewer.updateGraph({results:[a,b,{id:'c'}],nodes:[]});
    assert.equal(viewer.selectedNodeId(),'action:b');
    assert.equal(viewer.isAmoStep(),false);
    const old = components().workflowExecutionViewer({results:[{id:'a',type:'amocrm_add_note',output:{action:'note_created'}}],nodes:[]});
    assert.equal(old.responseData().action,'note_created');
    assert.match(old.requestData().message,/не сохранён/);
});

test('JSON input renders compact nested values while insertion keeps the original body path', () => {
    const fields = [
        {key:'.body',parent:null,label:'Результат',type:'object',available:true},
        {key:'.body.items',parent:'.body',label:'items',type:'array',available:true},
        {key:'.body.items[0]',parent:'.body.items',label:'[0]',type:'object',available:true},
        {key:'.body.items[0].id',parent:'.body.items[0]',label:'id',type:'int',value:42,available:true,expression:'{{ $node["Вебхук"].json.body.items[0].id }}'},
        {key:'.body.ok',parent:'.body',label:'ok',type:'bool',value:false,available:true},
    ];
    const picker = components().workflowExpressionPicker([{id:'trigger',available:true,fields}]);
    const json = picker.jsonRows.map(row => row.label + row.text).join('\n');
    assert.deepEqual(JSON.parse(json), {items:[{id:42}],ok:false});
    assert.equal(picker.jsonRows.find(row => row.item?.label === 'id').item.expression,fields[3].expression);
    picker.toggleJson(fields[1]);
    assert.equal(picker.jsonRows.some(row=>row.item?.label==='id'),false);
    picker.toggleJson(fields[1]);
    assert.equal(picker.jsonRows.some(row=>row.item?.label==='id'),true);
});

test('history preserves exact attempts, trigger selection and HTTP selection during polling', () => {
    const a={id:'a',execution_id:10}, b={id:'b',execution_id:11};
    const viewer=components().workflowExecutionViewer({results:[a,b],nodes:[]});
    viewer.exchangeIndex=1;
    viewer.updateGraph({results:[a,b,{id:'a',execution_id:12},{id:'b',execution_id:13}],nodes:[]});
    assert.equal(viewer.current().execution_id,10);
    assert.equal(viewer.exchangeIndex,1);
    viewer.previous();
    assert.equal(viewer.selectedIndex,-1);
    viewer.updateGraph({results:[a,b],nodes:[]});
    assert.equal(viewer.selectedIndex,-1);
    viewer.next();
    assert.equal(viewer.current().execution_id,10);
});

test('all three executions of the same node can be selected with distinct request and response bodies', () => {
    const results = [1,2,3].map(n => ({id:'note',type:'amocrm_add_note',execution_id:n,occurrence:n,occurrence_count:3,
        output:{amo_exchange:[{request:{body:{text:'Attempt '+n}},response:{body:{id:n}}}]}}));
    const viewer = components().workflowExecutionViewer({results,nodes:[]});
    viewer.selectNode('action:note');
    assert.equal(viewer.executions().length,3);
    for (let index=0; index<3; index++) {
        viewer.selectExecution(index);
        assert.equal(viewer.responseData().id,index+1);
        assert.equal(viewer.requestData().text,'Attempt '+(index+1));
        assert.match(viewer.executionLabel(viewer.current()),new RegExp('Выполнение '+(index+1)+' из 3'));
    }
    const legacy = components().workflowExecutionViewer({results:[{id:'contact',type:'amocrm_create_contact',input:{name:'Test'},output:{entity_id:77,deduplicated:true}}],nodes:[]});
    assert.equal(legacy.responseData().entity_id,77);
    assert.match(legacy.requestData().message,/POST создания не отправлялся/);
});

test('variable autocomplete filters, replaces only its token and preserves surrounding text', () => {
    const field = components().workflowValueField('Сделка {{ id }} готова');
    field.init();
    assert.equal(field.mode, 'expression');
    field.sources = [{name:'Запрос сделок', fields:[{label:'id', expression:'{{ $node["fetch"].json.id }}'}]}];
    const input = {value:'Сделка {{ id }} готова', selectionStart:12, hasAttribute:()=>true, focus(){}, setSelectionRange(){}, dispatchEvent(){}};
    field.suggest({target:input});
    assert.equal(field.suggestions.length, 1);
    field.$refs = {value:input};
    field.$nextTick = () => {};
    field.chooseSuggestion(field.suggestions[0]);
    assert.equal(field.state, 'Сделка {{ $node["fetch"].json.id }} готова');
    assert.equal(field.suggestions.length, 0);
});

test('autocomplete ignores plain text and closed tokens, and limits suggestions', () => {
    const field = components().workflowValueField('');
    field.sources = [{name:'Шаг',fields:Array.from({length:20}, (_,i)=>({expression:'{{ x'+i+' }}'}))}];
    for (const value of ['hello', '{{ x0 }}']) {
        field.suggest({target:{value, selectionStart:value.length, hasAttribute:()=>true}});
        assert.equal(field.suggestions.length,0);
    }
    field.suggest({target:{value:'{{',selectionStart:2,hasAttribute:()=>true}});
    assert.equal(field.suggestions.length,8);
    field.suggestionKey({key:'ArrowUp',preventDefault(){}});
    assert.equal(field.suggestionIndex,7);
    field.suggestionKey({key:'Escape',preventDefault(){},stopPropagation(){}});
    assert.equal(field.suggestions.length,0);
});

test('input panel selects the latest available step and bounds the visible fields', () => {
    const picker = components().workflowExpressionPicker([
        {id: 'trigger', available: true, fields: []},
        {id: 'query', available: true, fields: Array.from({length: 70}, (_, i) => ({path: String(i)}))},
        {id: 'pending', available: false, fields: []},
    ]);
    assert.equal(picker.selectedId, 'query');
    assert.equal(picker.visibleFields.length, 30);
    picker.limit += 30;
    assert.equal(picker.visibleFields.length, 60);
    picker.selectedId = 'trigger';
    assert.equal(picker.visibleFields.length, 0);
});

test('unexecuted steps and empty input sources have safe defaults', () => {
    const factory = components().workflowExpressionPicker;
    assert.equal(factory([{id:'trigger'}, {id:'previous'}]).selectedId, 'previous');
    assert.equal(factory([]).visibleFields.length, 0);
});

test('input tree hides repeated paths and only expands the selected branch', () => {
    const picker = components().workflowExpressionPicker([{id:'query', fields:[
        {key:'', parent:null, label:'Результат'},
        {key:'.items', parent:'', label:'items', type:'array', count:1},
        {key:'.items[0]', parent:'.items', label:'[0]', type:'object'},
        {key:'.items[0].id', parent:'.items[0]', label:'id', expression:'{{ $node["query"].json.items[0].id }}'},
        {key:'.count', parent:'', label:'count'},
    ]}]);
    assert.equal(picker.visibleFields.map(item => item.label).join(','), 'items,count');
    picker.toggleField(picker.visibleFields[0]);
    assert.equal(picker.visibleFields.map(item => item.label).join(','), 'items,[0],count');
    picker.toggleField(picker.visibleFields[1]);
    assert.equal(picker.visibleFields[2].expression, '{{ $node["query"].json.items[0].id }}');
    picker.toggleField(picker.visibleFields[0]);
    assert.equal(picker.visibleFields.length, 2);
    assert.equal(picker.fieldSummary({type:'array',count:20}), '[20]');
    assert.equal(picker.fieldSummary({type:'object'}), '{}');
});

test('reference browser paginates, filters, and drills into field options without expanding all rows', () => {
    const browser = components().workflowVariableBrowser({Fields: Array.from({length: 45}, (_, i) => ({
        label: 'Поле ' + i, value: String(i), options: [{id: 100 + i, name: 'Вариант ' + i}],
    }))});
    assert.equal(browser.items.length, 0);
    browser.type = 'Fields';
    assert.equal(browser.visibleItems.length, 20);
    assert.equal(browser.pageCount, 3);
    browser.page = 2;
    assert.equal(browser.visibleItems.length, 5);
    browser.showOptions(browser.items[0]);
    assert.equal(browser.page, 0);
    assert.equal(browser.visibleItems[0].value, '100');
    browser.reset();
    assert.equal(browser.items.length, 45);
    browser.query = 'поле 44';
    assert.equal(browser.visibleItems.length, 1);
    assert.equal(browser.visibleItems[0].value, '44');
});
