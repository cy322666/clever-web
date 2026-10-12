import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';

function fixture() {
    const events = [];
    const window = {addEventListener() {}, dispatchEvent: event => events.push(event)};
    runInNewContext(readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8'), {
        window, document: {addEventListener() {}, querySelector: () => null}, CustomEvent: class {constructor(type, props) {this.type=type;Object.assign(this,props);}},
    });
    const panel = window.workflowInspector();
    panel.$wire = {$id:'flow',mountedActions:[]};
    panel.$el = {querySelector:()=>null};
    panel.$nextTick = fn => fn();
    return {window, panel, events};
}

test('inspector opens immediately, serializes fast clicks and ends on the last requested node', async () => {
    const {panel,events} = fixture();
    const calls=[]; let release;
    panel.$wire.openWorkflowInspector = async id => {
        calls.push(id);
        if (calls.length === 1) await new Promise(resolve=>release=resolve);
        panel.$wire.editingActionId = id.slice(7);
        panel.$wire.mountedActions=[{name:'configureWorkflowAction'}];
    };
    const pending = panel.openInspector({nodeId:'action:first',name:'Первый'});
    assert.equal(panel.opened,true); assert.equal(panel.loading,true); assert.equal(panel.title,'Первый');
    panel.openInspector({nodeId:'action:second',name:'Второй'});
    panel.openInspector({nodeId:'action:third',name:'Третий'});
    assert.deepEqual(calls,['action:first']);
    release(); await pending;
    assert.deepEqual(calls,['action:first','action:third']);
    assert.equal(panel.currentId,'action:third'); assert.equal(panel.loading,false);
    assert.equal(events[0].detail.open,true);
});

test('tabs change locally and do not load or save the form', () => {
    const {panel}=fixture();
    panel.nextTab(1);assert.equal(panel.tab,'input');
    panel.nextTab(1);assert.equal(panel.tab,'output');
    panel.nextTab(1);assert.equal(panel.tab,'settings');
    panel.nextTab(-1);assert.equal(panel.tab,'output');
});

test('invalid form stays open on close and failed requests always release loading', async () => {
    const {panel}=fixture();panel.opened=true;
    panel.$wire.mountedActions=[{name:'configureWorkflowAction'}];
    panel.$wire.callMountedAction=async()=>{};
    await panel.closeInspector();assert.equal(panel.opened,true);assert.equal(panel.loading,false);
    panel.$wire.callMountedAction=async()=>{throw new Error('offline');};
    await panel.closeInspector();assert.equal(panel.opened,true);assert.match(panel.error,/Повторите/);assert.equal(panel.loading,false);
    panel.$wire.unmountAction=async()=>{panel.$wire.mountedActions=[];};
    await panel.closeInspector(false);assert.equal(panel.opened,false);
});

test('closing during load waits for the request then closes without leaving a stale panel', async () => {
    const {panel}=fixture();let release;
    panel.$wire.openWorkflowInspector=async()=>{await new Promise(r=>release=r);panel.$wire.editingActionId='first';panel.$wire.mountedActions=[{name:'configureWorkflowAction'}];};
    panel.$wire.callMountedAction=async()=>{panel.$wire.mountedActions=[];};
    const pending=panel.openInspector({nodeId:'action:first',name:'Первый'});
    panel.closeInspector();release();await pending;
    assert.equal(panel.opened,false);assert.equal(panel.loading,false);
});

test('inline rename validates, retains rejected input and does not open a modal', async () => {
    const {window}=fixture();const state=window.workflowInlineNodeName('a','Нода');
    state.$wire={workflowActions:[{id:'a',name:'Нода'}],renameWorkflowNode:async(id,name)=>{if(name!=='Занято') state.$wire.workflowActions[0].name=name;}};
    state.$nextTick=fn=>fn();state.$refs={nodeNameInput:{focus(){},select(){}}};
    state.startRename();assert.equal(state.nameDraft,'Нода');
    state.nameDraft=' ';await state.commitRename();assert.equal(state.renaming,true);
    state.nameDraft='Занято';await state.commitRename();assert.equal(state.renaming,true);assert.ok(state.renameError);
    state.nameDraft='Новая нода';await state.commitRename();assert.equal(state.renaming,false);assert.equal(state.nodeName,'Новая нода');
    state.startRename();state.nameDraft='Отмена';state.renaming=false;await state.commitRename();
    assert.equal(state.nodeName,'Новая нода','Escape must not commit on blur');
});

test('dock position uses individual style properties so resizing cannot override x-show display:none', () => {
    const {panel}=fixture();panel.positionInspector();
    assert.equal(typeof panel.position,'object');assert.equal(panel.position.top,'100px');
    assert.equal('display' in panel.position,false);
});
