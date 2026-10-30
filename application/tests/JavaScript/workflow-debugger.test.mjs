import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';

function fixture() {
    const events = [], window = {addEventListener() {}, dispatchEvent: event => events.push(event)};
    runInNewContext(readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8'), {
        window, document: {addEventListener() {}, querySelector: () => null},
        CustomEvent: class {constructor(type, props) {this.type = type; Object.assign(this, props);}},
    });
    const panel = window.workflowDebugPanel();
    panel.$wire = {$id:'flow',debugOpen:true,debugState:{status:'ready',results:[],next_id:'a'},workflowActions:[{id:'a',name:'Проверка'},{id:'loop',name:'Каждый заказ'}]};
    panel.$watch = () => {};
    panel.initDebugPanel();
    return {panel, events};
}

test('local node and occurrence selection shows the latest pass then moves through recorded passes', () => {
    const {panel} = fixture();
    panel.$wire.debugState.results = [{id:'a',display_output:{passed:true},iteration:[{node:'loop',index:0}]},{id:'other'},{id:'a',display_output:{passed:false},iteration:[{node:'loop',index:2}]}];
    panel.selectDebugNode('a');
    assert.equal(panel.selectedIndex,2);
    assert.equal(panel.debugOutputValue().passed,false);
    panel.moveDebugOccurrence(-1);
    assert.equal(panel.selectedIndex,0);
    assert.match(panel.debugOccurrenceLabel(panel.debugCurrent(),0),/Каждый заказ: 1/);
    panel.updateDebugSelection();
    assert.equal(panel.selectedIndex,0,'new results must not steal an inspected pass');
    panel.selectDebugNode('loop');
    assert.equal(panel.selectedIndex,-1);
    assert.equal(panel.debugOccurrences().length,0);
});

test('debug input and output never fall back to internal node settings', () => {
    const {panel} = fixture();
    panel.$wire.debugState.results = [{id:'a',input:{logic:'and',conditions:[{left:null}]},resolved_input:{logic:'and'},output:{condition_results:[]}}];
    panel.selectDebugNode('a');
    assert.match(panel.debugInputValue().message,/не записаны/);
    assert.match(panel.debugOutputValue().message,/не записаны/);
    panel.$wire.debugState.results[0].display_input = {count:0,items:[]};
    panel.$wire.debugState.results[0].display_output = false;
    assert.equal(panel.debugInputValue().count,0);
    assert.equal(panel.debugOutputValue(),false);
    assert.equal(panel.debugOutputEmpty(),false);
    panel.$wire.debugState.results[0].display_output = [];
    assert.equal(panel.debugOutputEmpty(),true);
});

test('single step serializes requests and returns to a paused ready state', async () => {
    const {panel} = fixture(); let release, calls = 0;
    panel.$wire.nextWorkflowDebugStep = async () => {calls++; await new Promise(resolve => release=resolve); panel.$wire.debugState.results.push({id:'a',status:'completed'}); return true;};
    const pending = panel.nextDebug();
    assert.equal(panel.busy,true);
    await panel.nextDebug(); assert.equal(calls,1);
    release(); await pending;
    assert.equal(panel.busy,false); assert.equal(panel.debugRunLabel(),'Пауза'); assert.equal(panel.selectedIndex,0);
});

test('pause waits for the in-flight action and never starts the following step', async () => {
    const {panel} = fixture(); let release, calls=0;
    panel.$wire.nextWorkflowDebugStep = async () => {calls++; await new Promise(resolve => release=resolve); return true;};
    const pending = panel.runDebug();
    panel.playing=false;
    assert.match(panel.debugRunLabel(),/Ожидаем/);
    await panel.closeDebug(); assert.equal(panel.$wire.debugOpen,true);
    release(); await pending;
    assert.equal(calls,1); assert.equal(panel.busy,false); assert.equal(panel.playing,false);
});

test('completion stops auto execution and network failures do not retry real actions', async () => {
    const {panel} = fixture(); let calls=0;
    panel.$wire.nextWorkflowDebugStep=async()=>{calls++;panel.$wire.debugState.status='completed';return false;};
    await panel.runDebug(); assert.equal(calls,1); assert.equal(panel.debugRunLabel(),'Завершено');
    panel.$wire.debugState.status='ready';
    panel.$wire.nextWorkflowDebugStep=async()=>{calls++;throw Error('offline');};
    await panel.runDebug(); await panel.nextDebug(); await panel.runDebug();
    assert.equal(calls,2); assert.equal(panel.busy,false); assert.match(panel.debugError,/мог выполниться/);
});

test('validation does not run steps; historical and stale sessions cannot continue', async () => {
    const {panel} = fixture();
    panel.$wire.startWorkflowDebug=async()=>false;
    panel.$wire.nextWorkflowDebugStep=()=>assert.fail('must not run');
    await panel.startDebug(true); assert.match(panel.debugError,/Запуск не начат/);
    panel.debugError='';
    for (const status of ['historical','stale','expired','failed']) {panel.$wire.debugState.status=status;await panel.runDebug();await panel.nextDebug();}
});

test('settings mount the existing form inside the debug tab without closing history', async () => {
    const {panel,events}=fixture();
    panel.$wire.debugState.results=[{id:'a',output:{count:1}}];panel.selectDebugNode('a');
    panel.$wire.closeWorkflowDebugger=()=>assert.fail('Settings must keep the debugger open');
    await panel.openDebugSettings();
    const opened=events.find(event=>event.type==='workflow-debug-settings' && event.detail.open);
    assert.equal(opened.detail.nodeId,'action:a');
    assert.equal(opened.detail.slotId,'workflow-debug-settings-flow');
    assert.equal(panel.debugTab,'settings');
    assert.equal(panel.$wire.debugOpen,true);
    assert.equal(panel.debugResults().length,1);
});

test('the debugger has three tabs, a compact history dropdown and no bottom trail', () => {
    const css=readFileSync(new URL('../../resources/css/workflow-debugger.css',import.meta.url),'utf8');
    assert.match(css,/\.workflow-debug-ui\[hidden\] \{ display: none !important;/);
    assert.match(css,/\.workflow-node-editor\[data-workflow-debug-open=true\] \.workflow-node-card__tools/);
    assert.match(css,/\.workflow-inspector\[data-debug-embedded\]/);
    const view=readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflow-builder.blade.php',import.meta.url),'utf8');
    assert.doesNotMatch(view,/@include\('filament.workflow-builder.workflow-debug-(trail|toolbar)/);
    assert.doesNotMatch(css,/workflow-debug-toolbar/);
    const panel=readFileSync(new URL('../../resources/views/filament/workflow-builder/workflow-debugger.blade.php',import.meta.url),'utf8');
    assert.match(panel,/<details x-ref="debugHistory"/);
    assert.match(panel,/\['settings' => 'Настройки', 'input' => 'Вход', 'output' => 'Выход'\]/);
    assert.match(panel,/'numbered' => true/);
    assert.doesNotMatch(panel,/summary'|Детали запроса|Технические события|Повторить|Таблица|Открыть настройки ноды|<select/);
});

test('history is fetched once on demand, search and record navigation stay local', async () => {
    const {panel}=fixture(); let calls=0;
    panel.$wire.recentDebugRuns=async()=>{calls++;return {'10':'Вчера · Выполнено','11':'Сегодня · Ошибка'};};
    assert.equal(calls,0);
    await panel.loadDebugHistory();await panel.loadDebugHistory();assert.equal(calls,1);
    assert.equal(panel.filteredDebugHistory()[0].id,11);
    panel.historyQuery='вчера';assert.equal(panel.filteredDebugHistory()[0].id,10);
    await panel.loadDebugHistory(true);assert.equal(calls,2);
    const writes=[];
    panel.$wire.$set=(key,value,live)=>writes.push({key,value,live});
    panel.$wire.debugState.results=[{id:'a',display_output:0},{id:'a',display_output:false}];
    panel.selectDebugNode('a');panel.moveDebugOccurrence(-1);
    assert.equal(panel.debugRecordLabel(),'Запись 1 из 2');
    assert.equal(writes.at(-1).value,0);assert.equal(writes.at(-1).live,false);
    assert.equal(calls,2);
});

test('switching history keeps the browser draft, mounted settings and selection', async () => {
    const {panel}=fixture();
    const draft={trigger:{type:'manual'},description:'Unsaved edits'}, form={data:{text:'Unsaved settings'}};
    panel.$wire.definition=draft;panel.$wire.mountedActions=[form];
    panel.selectDebugNode('a');panel.$refs={debugHistory:{open:true}};
    panel.$wire.inspectWorkflowDebugRun=async id=>{assert.equal(id,41);panel.$wire.debugState={status:'historical',run_id:id,results:[{id:'a',display_output:{id:41}}]};return true;};
    await panel.inspectDebugRun(41);
    assert.equal(panel.$wire.definition,draft);assert.equal(panel.$wire.mountedActions[0],form);
    assert.equal(panel.selectedDebugId,'a');assert.equal(panel.debugOutputValue().id,41);
    assert.equal(panel.$refs.debugHistory.open,false);assert.equal(panel.debugHistoryLabel(),'История · #41');
});

test('a node test adds a fresh result while every recorded loop pass remains inspectable', () => {
    const {panel}=fixture();
    const history=[{id:'a',display_output:1},{id:'a',display_output:2}];
    panel.$wire.debugState={status:'historical',run_id:5,results:history};
    panel.selectDebugResult(0);
    panel.$wire.nodeRunResults={a:{id:'a',status:'completed',display_output:3}};
    panel.updateDebugSelection({preview_node_id:'a'});
    assert.equal(panel.debugResults().length,3);assert.equal(panel.debugOutputValue(),3);
    assert.equal(panel.debugTab,'output');assert.equal(panel.$wire.debugState.results,history);
    panel.moveDebugOccurrence(-1);assert.equal(panel.debugOutputValue(),2);
});

test('automatic execution preserves the pass selected by the user while explicit next follows execution', async () => {
    const {panel}=fixture();
    panel.$wire.debugState.results=[{id:'a',output:{id:1}},{id:'a',output:{id:2}}];
    panel.selectDebugResult(0);
    panel.$wire.nextWorkflowDebugStep=async()=>{panel.$wire.debugState.results.push({id:'a',output:{id:3}});panel.$wire.debugState.status='completed';return false;};
    await panel.runDebug();assert.equal(panel.selectedIndex,0);
    panel.$wire.debugState.status='ready';await panel.nextDebug();assert.equal(panel.selectedIndex,3);
});

test('the canvas shows the inspected pass, not the opposite branch from the latest loop iteration', () => {
    const {panel}=fixture();
    panel.$wire.debugState.results=[{id:'a',output:{passed:false}},{id:'a',output:{passed:true}}];
    panel.selectDebugResult(0);
    assert.equal(panel.debugCanvasState().results.at(-1).output.passed,false);
    assert.equal(panel.debugCanvasState().next_id,null);
    assert.equal(panel.$wire.debugState.results.length,2,'inspection cannot alter the live session');
    panel.$wire.debugOpen=false;
    assert.equal(panel.debugCanvasState().results.at(-1).output.passed,true);
});

test('failed reset keeps the inspected result and does not claim an action was executed', async () => {
    const {panel}=fixture();
    panel.$wire.debugState.results=[{id:'a',output:{id:1}}];panel.selectDebugResult(0);
    panel.$wire.editWorkflowDebugInput=async()=>{throw Error('offline');};
    await panel.resetDebug();
    assert.equal(panel.selectedIndex,0);assert.equal(panel.selectedDebugId,'a');
    assert.match(panel.debugError,/Не удалось связаться/);
    assert.doesNotMatch(panel.debugError,/мог выполниться/);
});
