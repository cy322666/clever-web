import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';

function fixture() {
    const events = [];
    const window = {addEventListener() {}, dispatchEvent: event => events.push(event)};
    const attributes = new Set();
    const editorPage = {
        toggleAttribute: (name, enabled) => enabled ? attributes.add(name) : attributes.delete(name),
        removeAttribute: name => attributes.delete(name),
        hasAttribute: name => attributes.has(name),
    };
    runInNewContext(readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8'), {
        window, document: {addEventListener() {}, querySelector: selector => selector === '.workflow-editor-page' ? editorPage : null}, CustomEvent: class {constructor(type, props) {this.type=type;Object.assign(this,props);}},
    });
    const panel = window.workflowInspector();
    panel.$wire = {$id:'flow',mountedActions:[]};
    panel.$el = {querySelector:()=>null, contains:()=>false};
    panel.$nextTick = fn => fn();
    return {window, panel, events, editorPage};
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

test('inline rename is optimistic, confirms the draft, and restores a rejected value', async () => {
    const {window}=fixture();const state=window.workflowInlineNodeName('a','Нода');
    let release;
    state.$wire={workflowActions:[{id:'a',name:'Нода'}],renameWorkflowNode:async(id,name,inline)=>{
        assert.equal(inline,true);
        if(name==='Занято') throw new Error('Duplicate');
        await new Promise(resolve=>release=resolve);
        state.$wire.workflowActions[0].name=name;
        return name;
    }};
    state.$nextTick=fn=>fn();state.$refs={nodeNameInput:{focus(){},select(){}}};
    state.startRename();assert.equal(state.nameDraft,'Нода');
    state.nameDraft=' ';await state.commitRename();assert.equal(state.renaming,true);
    state.nameDraft='Занято';await state.commitRename();assert.equal(state.renaming,true);assert.ok(state.renameError);
    state.nameDraft='Новая нода';const pending=state.commitRename();
    assert.equal(state.renaming,false);assert.equal(state.nodeName,'Новая нода');
    release();await pending;assert.equal(state.nodeName,'Новая нода');
    state.startRename();state.nameDraft='Отмена';state.renaming=false;await state.commitRename();
    assert.equal(state.nodeName,'Новая нода','Escape must not commit on blur');
});

test('inline rename commits on outside pointer press even if the canvas prevents blur', () => {
    const markup=readFileSync(new URL('../../resources/views/filament/workflow-builder/workflow-inline-node-name.blade.php',import.meta.url),'utf8');
    assert.match(markup,/x-on:pointerdown\.window="if \(renaming && !\$el\.contains\(\$event\.target\)\) commitRename\(\)"/);
});

test('filter fields come from the selected collection, never indexed rows', () => {
    const {window}=fixture();
    const sources=[{id:'a',name:'Сделки',fields:[
        {type:'array',key:'',expression:'{{ $("Сделки") }}',item_fields:['id','status_id','contact.name']},
        {type:'object',key:'[0]',expression:null},
    ]},{id:'b',name:'Контакты',fields:[{type:'array',key:'',expression:'{{ $("Контакты") }}',item_fields:['id','phone']}]}];
    assert.deepEqual(Array.from(window.workflowFilterItemFields(sources,'{{ $("Сделки") }}')),['id','status_id','contact.name']);
    assert.deepEqual(Array.from(window.workflowFilterItemFields(sources,'{{ $("Контакты") }}')),['id','phone']);
    assert.deepEqual(Array.from(window.workflowFilterItemFields(sources,'{{ $json.items }}')),['id','phone']);
    assert.equal(window.workflowFilterItemPath('{{ $("Сделки")[0].status_id }}','{{ $("Сделки") }}'),'status_id');
    assert.equal(window.workflowFilterItemPath('{{ $("Сделки").name }}','{{ $("Сделки") }}'),'name');
    const field=window.workflowValueField('', 'mountedActions.0.data.items');
    field.$wire={$get:()=> '{{ $("Сделки") }}'};
    field.sources=sources;
    field.suggest({target:{value:'sta',hasAttribute:()=>true}});
    assert.deepEqual(Array.from(field.suggestions,item=>item.expression),['status_id']);
    assert.ok(field.suggestions.every(item=>!item.expression.includes('[0]')));
});

test('dock positioning cannot override panel visibility', () => {
    const {panel}=fixture();panel.positionInspector();
    assert.equal(typeof panel.position,'object');assert.equal(panel.position.top,'100px');
    assert.equal('display' in panel.position,false);
});

test('closing node settings immediately releases canvas space', () => {
    const {panel,editorPage}=fixture();
    panel.opened=true;
    panel.announce();
    assert.equal(editorPage.hasAttribute('data-workflow-inspector-open'),true);
    assert.equal(panel.$el.hidden,false);
    panel.hideInspector();
    assert.equal(editorPage.hasAttribute('data-workflow-inspector-open'),false);
    assert.equal(panel.$el.hidden,true);
    panel.opened=true;
    panel.announce();
    panel.destroy();
    assert.equal(editorPage.hasAttribute('data-workflow-inspector-open'),false);
});

test('a late Livewire redraw cannot restore the empty inspector column', () => {
    const {window,panel,editorPage}=fixture();
    let morphed;
    window.Livewire={hook:(name, callback) => {
        assert.equal(name,'morphed');
        morphed=callback;
        return () => { morphed=null; };
    }};
    panel.init();
    panel.opened=true;
    panel.announce();
    editorPage.removeAttribute('data-workflow-inspector-open');
    morphed({component:{id:'flow'}});
    assert.equal(editorPage.hasAttribute('data-workflow-inspector-open'),true);
    panel.hideInspector();
    editorPage.toggleAttribute('data-workflow-inspector-open',true);
    morphed({component:{id:'flow'}});
    assert.equal(editorPage.hasAttribute('data-workflow-inspector-open'),false);
    panel.destroy();
    assert.equal(morphed,null);
});

test('canvas space follows inspector visibility without a stale sibling selector', () => {
    const view=readFileSync(new URL('../../resources/views/filament/workflow-builder/workflow-inspector.blade.php',import.meta.url),'utf8');
    const canvas=readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflow-builder.blade.php',import.meta.url),'utf8');
    const css=readFileSync(new URL('../../resources/css/workflow-inspector.css',import.meta.url),'utf8');
    assert.match(view,/x-bind:hidden="!opened" hidden/);
    assert.doesNotMatch(canvas,/inspectorOpen|has-inspector/);
    assert.match(css,/\.workflow-inspector\[hidden\]\s*\{\s*display: none !important;/);
    assert.match(css,/\.workflow-editor-page\[data-workflow-inspector-open\] \.workflow-workbench__main \{ padding-right: 440px; \}/);
    assert.doesNotMatch(css,/\.workflow-editor-page:has\(~ .*\.workflow-inspector/);
});

test('outside pointer hides immediately and reopens the same incomplete draft without a request', async () => {
    const {panel,events}=fixture();
    panel.opened=true;panel.currentId='action:first';
    const draft={name:'configureWorkflowAction',data:{seconds:''}};
    panel.$wire.mountedActions=[draft];
    panel.$wire.openWorkflowInspector=()=>assert.fail('A mounted draft must not reload');
    panel.$wire.callMountedAction=()=>assert.fail('Dismissing must not validate or discard');
    panel.dismissInspector({target:{}});
    assert.equal(panel.opened,false);assert.equal(panel.loading,false);
    assert.equal(events.at(-1).detail.open,false);
    panel.inspectorMounted({dataset:{inspectorNode:'action:first',inspectorTitle:'Первый'}});
    assert.equal(panel.opened,false,'An unrelated morph cannot reopen a dismissed dock');
    await panel.openInspector({nodeId:'action:first',name:'Первый'});
    assert.equal(panel.opened,true);assert.equal(panel.loading,false);
    assert.equal(panel.$wire.mountedActions[0],draft);assert.equal(draft.data.seconds,'');
});

test('inside controls and teleported dropdowns do not dismiss the inspector', () => {
    const {panel}=fixture();panel.opened=true;
    panel.$el.contains=()=>true;
    panel.dismissInspector({target:{}});assert.equal(panel.opened,true);
    panel.$el.contains=()=>false;
    panel.dismissInspector({target:{closest:()=>({})}});assert.equal(panel.opened,true);
});

test('opening and copying from references preserves the mounted unsaved settings', () => {
    const {panel}=fixture();panel.opened=true;
    const draft={name:'configureWorkflowAction',data:{name:'Unsaved contact'}};
    panel.$wire.mountedActions=[draft];
    panel.$wire.callMountedAction=()=>assert.fail('References must not save the form');
    for (const selector of ['[data-workflow-reference-toggle]','[data-workflow-reference-panel]']) {
        panel.dismissInspector({target:{closest:selectors=>selectors.includes(selector) ? {} : null}});
        assert.equal(panel.opened,true);
        assert.equal(panel.$wire.mountedActions[0],draft);
    }
    const view=readFileSync(new URL('../../resources/views/filament/workflow-builder/workflow-inspector.blade.php',import.meta.url),'utf8');
    const builder=readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflow-builder.blade.php',import.meta.url),'utf8');
    assert.doesNotMatch(view,/data-workflow-reference-toggle/);
    assert.match(builder,/data-workflow-reference-toggle/);
});

test('close button and Escape hide without a roundtrip; explicit Apply retains validation', () => {
    const {panel}=fixture();panel.opened=true;panel.currentId='action:a';panel.$wire.mountedActions=[{name:'configureWorkflowAction'}];
    panel.$wire.callMountedAction=()=>assert.fail('Close must be immediate');
    panel.hideInspector();assert.equal(panel.opened,false);assert.equal(panel.currentId,'action:a');
    const view=readFileSync(new URL('../../resources/views/filament/workflow-builder/workflow-inspector.blade.php',import.meta.url),'utf8');
    assert.match(view,/x-on:click="hideInspector\(\)" aria-label="Закрыть настройки"/);
    assert.match(view,/keydown.escape.stop="if \(!\$event.defaultPrevented\) hideInspector\(\)"/);
});

test('outside click during loading leaves the completed draft hidden and reusable', async () => {
    const {panel}=fixture();let release;
    panel.$wire.openWorkflowInspector=async()=>{await new Promise(r=>release=r);panel.$wire.editingActionId='first';panel.$wire.mountedActions=[{name:'configureWorkflowAction'}];};
    const pending=panel.openInspector({nodeId:'action:first',name:'Первый'});
    panel.dismissInspector({target:{}});
    assert.equal(panel.opened,false);
    release();await pending;
    assert.equal(panel.opened,false);assert.equal(panel.currentId,'action:first');
    panel.$wire.openWorkflowInspector=()=>assert.fail('Already loaded');
    await panel.openInspector({nodeId:'action:first',name:'Первый'});
    assert.equal(panel.opened,true);
});

test('validation on main Save reveals a hidden draft, but unrelated modal errors do not', () => {
    const {panel}=fixture();panel.dismissed=true;
    panel.showValidationErrors();assert.equal(panel.opened,false);
    panel.$wire.mountedActions=[{name:'configureWorkflowAction',data:{seconds:''}}];
    panel.showValidationErrors();assert.equal(panel.opened,true);assert.equal(panel.dismissed,false);assert.equal(panel.tab,'settings');
});

test('server-mounted settings for a new trigger open after another draft was dismissed', () => {
    const {panel}=fixture();panel.dismissed=true;panel.currentId='action:first';
    panel.inspectorMounted({dataset:{inspectorNode:'trigger:new',inspectorTitle:'По расписанию'}});
    assert.equal(panel.opened,true);assert.equal(panel.dismissed,false);
});

test('a stale catalog-close event does not hide settings mounted in the same response', () => {
    const {panel}=fixture();
    panel.$wire.mountedActions=[{name:'configureTrigger'}];
    panel.inspectorMounted({dataset:{inspectorNode:'trigger:new',inspectorTitle:'По расписанию'}});
    panel.syncInspectorActions({id:'flow',newActionNestingIndex:null});
    assert.equal(panel.opened,true);assert.equal(panel.currentId,'trigger:new');
    panel.$wire.mountedActions=[];
    panel.syncInspectorActions({id:'other',newActionNestingIndex:null});
    assert.equal(panel.opened,true);
    panel.syncInspectorActions({id:'flow',newActionNestingIndex:null});
    assert.equal(panel.opened,false);assert.equal(panel.currentId,null);
    const view=readFileSync(new URL('../../resources/views/filament/workflow-builder/workflow-inspector.blade.php',import.meta.url),'utf8');
    assert.match(view,/x-on:sync-action-modals.window="syncInspectorActions\(\$event.detail\)"/);
});
