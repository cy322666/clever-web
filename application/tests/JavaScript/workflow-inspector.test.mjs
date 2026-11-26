import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';

function fixture() {
    const events = [];
    const window = {innerHeight:1060, addEventListener() {}, dispatchEvent: event => events.push(event)};
    const attributes = new Set();
    const editorPage = {
        toggleAttribute: (name, enabled) => enabled ? attributes.add(name) : attributes.delete(name),
        removeAttribute: name => attributes.delete(name),
        hasAttribute: name => attributes.has(name),
    };
    runInNewContext(readFileSync(new URL('../../resources/js/workflows.js', import.meta.url), 'utf8'), {
        window, document: {addEventListener() {}, querySelector: selector => selector === '.workflow-editor-page' ? editorPage : null,
            getElementById: id => id === 'debug-slot' ? {getBoundingClientRect:()=>({top:280,left:840,width:600,bottom:1060})} : null}, CustomEvent: class {constructor(type, props) {this.type=type;Object.assign(this,props);}},
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

test('closing from a descendant button hides the dock without hiding the button',()=>{
    const {panel}=fixture();panel.init();panel.opened=true;
    const root=panel.$el,button={};
    const scoped=new Proxy(panel,{get(target,key,receiver){return key==='$el'?button:Reflect.get(target,key,receiver);}});
    scoped.hideInspector();
    assert.equal(root.hidden,true);assert.equal(button.hidden,undefined);
    panel.opened=true;panel.announce();assert.equal(root.hidden,false);
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

test('rename stays editable when the server does not confirm it', async () => {
    const {window}=fixture();const state=window.workflowInlineNodeName('a','Нода');
    state.$wire={workflowActions:[{id:'a',name:'Нода'}],renameWorkflowNode:async()=>undefined};
    state.$nextTick=fn=>fn();state.$refs={nodeNameInput:{focus(){},select(){}}};
    state.startRename();state.nameDraft='Новое имя';await state.commitRename();
    assert.equal(state.nodeName,'Нода');
    assert.equal(state.renaming,true);
    assert.match(state.renameError,/не подтверждено/);
});

test('filter fields come from the selected collection, never indexed rows', () => {
    const {window}=fixture();
    const sources=[{id:'a',name:'Сделки',fields:[
        {type:'array',key:'',expression:'{{ $("Сделки") }}',item_fields:['id','status_id','contact.name']},
        {type:'object',key:'[0]',expression:null},
    ]},{id:'b',name:'Контакты',fields:[{type:'array',key:'',expression:'{{ $("Контакты") }}',item_fields:['id','phone']}]}];
    assert.deepEqual(Array.from(window.workflowFilterItemFields(sources,'{{ $("Сделки") }}')),['id','status_id']);
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
    assert.equal(window.workflowFilterItemPath('{{ $("Сделки").status_id }}','{{ $("Сделки") }}'),'status_id');
    assert.equal(window.workflowFilterItemPath('{{ $("Контакты").phone }}','{{ $("Сделки") }}'),'{{ $("Контакты").phone }}');
    field.state='{{ $("Сделки").status_id }}';
    field.init();
    assert.equal(field.state,'status_id');
    assert.equal(field.mode,'value');
});

test('floating positioning leaves a closed panel hidden and puts open panels above the node when possible', () => {
    const {window,panel}=fixture();panel.positionInspector();
    assert.equal(Object.keys(panel.position).length,0);
    const bounds={left:72,right:1280,top:110,bottom:1000};
    const above=window.workflowInspectorPlacement({left:700,right:770,top:625,bottom:695},bounds,428,300);
    assert.equal(above.top,310); assert.equal(above.left,521); assert.equal(above.arrow,'above'); assert.equal(above.offset,214);
    const below=window.workflowInspectorPlacement({left:78,right:144,top:135,bottom:201},bounds,428,300);
    assert.equal(below.left,82); assert.equal(below.top,216); assert.equal(below.arrow,'below');
    const expanded=window.workflowInspectorPlacement({left:78,right:144,top:135,bottom:201},bounds,820,300,true);
    assert.equal(expanded.arrow,'below'); assert.equal(expanded.left,266); assert.equal(expanded.top,216);
    panel.opened=true;panel.positionInspector();
    assert.equal('display' in panel.position,false);
    window.innerWidth=320;
    panel.inspectorAnchor=()=>({setAttribute(){},removeAttribute(){},getBoundingClientRect:()=>({left:0,right:96,top:300,bottom:396})});
    panel.positionInspector();
    const left=parseFloat(panel.position.left),width=parseFloat(panel.position.width);
    assert.ok(left>=10,'The settings fields must fit at the left viewport edge');
    assert.ok(left+width<=310,'The settings fields must fit at the right viewport edge');
});

test('an inline test handles missing data and keeps simulation separate from real execution', async () => {
    const {window,panel}=fixture();
    const calls=[];
    window.confirm=()=>false;
    panel.$wire.nodeRunResults={};
    panel.$wire.runWorkflowCanvasNode=async (id,real)=>{
        calls.push([id,real]);return {status:'success',real,display_count:1};
    };
    const expression='{{ $("Контакты").id }}';
    panel.local={id:'action:a',node:()=>({}),config:{contact_id:expression},sources:()=>[{id:'upstream',available:false,fields:[{expression}]}],flush(){},renderInput(){}};
    await panel.runInspectorTest(false);
    assert.equal(panel.testSummary().text,'Нет данных из предыдущей ноды');
    assert.deepEqual(calls,[]);
    panel.local.sources=()=>[{id:'upstream',available:true,fields:[{expression}]}];
    await panel.runInspectorTest(false);
    assert.deepEqual(calls,[['a',false]]);
    assert.equal(panel.testSummary().text,'Симуляция завершена · Строк: 1');
    await panel.runInspectorTest(true);
    assert.equal(calls.length,1);
    window.confirm=()=>true;
    await panel.runInspectorTest(true);
    assert.deepEqual(calls,[['a',false],['a',true]]);
    panel.$wire.nodeRunResults.a={status:'success',real:true,display_count:42};
    panel.$wire.runWorkflowCanvasNode=async()=>null;
    await panel.runInspectorTest(true);
    assert.match(panel.testSummary().text,/недоступен/);
});

test('missing-data check covers interpolated tokens, optional paths and first or last list items', () => {
    const {window}=fixture();
    const sources=[
        {id:'step',available:true,fields:[
            {expression:'{{ $("Сделка").id }}', available:true},
            {expression:'{{ $("Сделка").contact_id }}', available:false},
        ]},
        {id:'empty',available:true,fields:[{type:'array',count:0,expression:'{{ $("Контакты") }}',available:true}]},
        {id:'not-run',available:false,fields:[{type:'array',count:null,expression:'{{ $("Список").items }}',available:false}]},
    ];
    for (const value of ['Копия {{ $("Сделка").contact_id }}','{{ $("Контакты").first().id }}','{{ $("Список").items.last()["имя"] }}']) {
        assert.equal(window.workflowCompactMissingInput(sources,{name:value}),true,value);
    }
    for (const value of ['{{ $("Сделка").id }}','{{ $("Сделка").contact_id || 0 }}','{{ $("Контакты") }}','Просто текст']) {
        assert.equal(window.workflowCompactMissingInput(sources,{name:value}),false,value);
    }
    assert.equal(window.workflowCompactMissingInput(sources,{javascript_code:'{{ $("Список").items }}'}),false);
});

test('real nested-workflow test explicitly warns that the child workflow will run', async () => {
    const {window,panel}=fixture();
    panel.local={id:'action:a',type:'run_workflow',node:()=>({}),config:{},sources:()=>[]};
    window.confirm=message=>{assert.match(message,/дочерний сценарий со всеми его действиями/);return false;};
    panel.$wire.runWorkflowCanvasNode=()=>assert.fail('Cancelled real test must not run');
    await panel.runInspectorTest(true);
});

test('closing node settings immediately clears the visibility marker', () => {
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

test('floating inspector does not reserve canvas space', () => {
    const view=readFileSync(new URL('../../resources/views/filament/workflow-builder/workflow-inspector.blade.php',import.meta.url),'utf8');
    const canvas=readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflow-builder.blade.php',import.meta.url),'utf8');
    const css=readFileSync(new URL('../../resources/css/workflow-inspector.css',import.meta.url),'utf8');
    assert.match(view,/x-bind:hidden="!opened" hidden/);
    assert.doesNotMatch(canvas,/inspectorOpen|has-inspector/);
    assert.match(css,/\.workflow-inspector\[hidden\]\s*\{\s*display: none !important;/);
    assert.doesNotMatch(css,/\.workflow-editor-page\[data-workflow-inspector-open\]/);
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

test('Escape hides without a roundtrip and the side controls have Apply with no close button', () => {
    const {panel}=fixture();panel.opened=true;panel.currentId='action:a';panel.$wire.mountedActions=[{name:'configureWorkflowAction'}];
    panel.$wire.callMountedAction=()=>assert.fail('Close must be immediate');
    panel.hideInspector();assert.equal(panel.opened,false);assert.equal(panel.currentId,'action:a');
    const view=readFileSync(new URL('../../resources/views/filament/workflow-builder/workflow-inspector.blade.php',import.meta.url),'utf8');
    assert.doesNotMatch(view,/aria-label="Закрыть настройки"/);
    assert.match(view,/x-on:click="closeInspector\(\)" aria-label="Применить"/);
    assert.match(view,/keydown.escape.stop="if \(!\$event.defaultPrevented\) hideInspector\(\)"/);
});

test('active inspector controls offer play and apply', () => {
    const view=readFileSync(new URL('../../resources/views/filament/workflow-builder/workflow-inspector.blade.php',import.meta.url),'utf8');
    assert.match(view,/heroicon-s-play/);
    assert.doesNotMatch(view,/heroicon-o-beaker|heroicon-o-arrows-pointing-out|x-on:click="toggleExpanded\(\)"/);
    assert.match(view,/x-on:click="closeInspector\(\)"/);
    assert.doesNotMatch(view,/toggleInspectorNode\(\)|deleteInspectorNode\(\)|heroicon-o-power|heroicon-o-trash/);
    assert.match(view,/class="workflow-settings-actions" role="toolbar"/);
    assert.doesNotMatch(view,/<header|data-inspector-icon|workflow-inspector__header/);
});

test('inspector deletion removes actions and starts without validating or saving their form', async () => {
    for (const id of ['action:a','trigger','trigger:timer']) {
        const {panel,window}=fixture();
        panel.opened=true;panel.currentId=panel.anchorId=id;
        const calls=[],flushes=[];
        panel.local={id,flush(){if(this.id) flushes.push(this.id);},node(){return {};}};
        panel.$wire.callMountedAction=()=>assert.fail('Deleting must not apply an invalid form');
        window.workflowEditorCall=async(wire,method,nodeId)=>{
            calls.push([method,nodeId]);
            assert.equal(panel.local.id,null,'A promoted start must never receive the old draft');
        };
        await panel.deleteInspectorNode();
        assert.deepEqual(calls,[[id.startsWith('trigger')?'removeTriggerNode':'removeWorkflowAction',id.startsWith('trigger')?id:'a']]);
        assert.deepEqual(flushes,[id]);
        assert.equal(panel.opened,false);assert.equal(panel.currentId,null);assert.equal(panel.deleting,false);
    }
});

test('failed inspector deletion keeps the draft editable and read-only inspection cannot delete', async () => {
    const {panel,window}=fixture();panel.opened=true;panel.currentId='action:a';
    panel.local={id:'action:a',flush(){},node(){return {};}};
    window.workflowEditorCall=async()=>{throw new Error('delete failed');};
    await panel.deleteInspectorNode();
    assert.equal(panel.local.id,'action:a');assert.equal(panel.opened,true);assert.equal(panel.deleting,false);
    assert.match(panel.error,/Не удалось удалить/);
    window.workflowEditorCall=()=>assert.fail('Read-only embedded inspection must not delete');
    panel.embedded=true;
    await panel.deleteInspectorNode();
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

test('debug settings reuse the same form and do not reserve another canvas column', async () => {
    const {panel,editorPage}=fixture();
    panel.$wire.debugOpen=true;
    panel.$wire.mountedActions=[{name:'configureWorkflowAction',data:{text:'Unsaved'}}];
    panel.currentId='action:a';
    panel.$wire.openWorkflowInspector=()=>assert.fail('The same form must not reload');
    await panel.syncDebugSettings({componentId:'flow',open:true,nodeId:'action:a',name:'Нода',slotId:'debug-slot'});
    assert.equal(panel.opened,true);assert.equal(panel.embedded,true);assert.equal(panel.tab,'settings');
    assert.equal(panel.position.top,'280px');assert.equal(panel.position.left,'840px');assert.equal(panel.position.width,'600px');
    assert.equal(editorPage.hasAttribute('data-workflow-inspector-open'),false);
    panel.dismissInspector({target:{}});assert.equal(panel.opened,true,'Canvas clicks do not hide the embedded tab');
    await panel.syncDebugSettings({componentId:'flow',open:false});
    assert.equal(panel.opened,false);assert.equal(panel.$wire.mountedActions[0].data.text,'Unsaved');
});

test('Escape from embedded settings returns to data instead of leaving a blank settings tab', async () => {
    const {panel,events}=fixture();panel.embedded=true;panel.opened=true;
    panel.hideInspector();
    assert.ok(events.some(event=>event.type==='workflow-debug-settings-dismissed' && event.detail.componentId==='flow'));
    const count=events.length;panel.hideInspector();
    assert.equal(events.length,count + 1,'A hidden form must not emit another dismiss event');
});

test('node controls follow the card and leave room between an upper form and the node', () => {
    const {window}=fixture();
    const bounds={left:0,right:1200,top:100,bottom:900};
    for (const anchor of [{left:400,right:496,top:500,bottom:596},{left:550,right:622,top:600,bottom:672}]) {
        const actions=window.workflowNodeActionsPlacement(anchor,bounds,130,28);
        assert.equal(actions.left+130,anchor.right);
        assert.equal(actions.top+28+8,anchor.top);
        const form=window.workflowInspectorPlacement(anchor,bounds,428,150,false,36);
        assert.equal(form.arrow,'above');
        assert.ok(form.top+150+8<=actions.top,'The form must not cover the node controls');
    }
    for (const anchor of [{left:-40,right:56,top:105,bottom:201},{left:1160,right:1256,top:820,bottom:916}]) {
        const actions=window.workflowNodeActionsPlacement(anchor,bounds,130,28);
        assert.ok(actions.left>=10 && actions.left+130<=1190);
        assert.ok(actions.top>=108 && actions.top+28<=892);
    }
});

test('inspector power flushes the selected draft and targets the matching canvas component', () => {
    for (const id of ['action:a','trigger','trigger:timer']) {
        const {panel,events}=fixture();let flushed=0;
        panel.opened=true;panel.currentId=panel.anchorId=id;
        panel.local={flush(){flushed++;},synchronize(){},node(){return {disabled:true};}};
        assert.equal(panel.inspectorNodeDisabled(),true);
        panel.toggleInspectorNode();
        assert.equal(flushed,1);
        const event=events.find(event=>event.type==='workflow-node-toggle');
        assert.equal(event.detail.componentId,'flow');assert.equal(event.detail.nodeId,id);
        for (const key of ['loading','processing','deleting','runningTest','embedded']) {
            events.length=0;panel[key]=true;panel.toggleInspectorNode();
            assert.equal(events.length,0);panel[key]=false;
        }
        events.length=0;panel.anchorId='action:different';panel.toggleInspectorNode();
        assert.equal(events.length,0);
    }
});


test('tall settings never cover their node or toolbar and scroll when vertical space is limited',()=>{
    const {window}=fixture();
    const bounds={left:0,right:600,top:64,bottom:720};
    const anchor={left:270,right:366,top:590,bottom:686};
    const actions=window.workflowNodeActionsPlacement(anchor,bounds,62,28);
    const controls={...actions,right:actions.left+62,bottom:actions.top+28};
    const placed=window.workflowInspectorPlacement(anchor,bounds,428,1000,false,36,controls);
    assert.equal(placed.arrow,'above');
    assert.equal(placed.maxHeight,465);
    assert.equal(placed.top,74);
    assert.ok(placed.top+placed.maxHeight+15<=controls.top);
    const wide=window.workflowInspectorPlacement(anchor,{...bounds,right:1280},428,600,false,36,controls);
    assert.equal(wide.arrow,null);
    assert.ok(wide.left>=anchor.right+15);
    assert.equal(wide.maxHeight,600);
});

test('settings and controls stay disjoint across viewport sizes, zoom and expanded forms',()=>{
    const {window}=fixture();
    const overlaps=(a,b)=>a.left<b.right && a.right>b.left && a.top<b.bottom && a.bottom>b.top;
    for(const viewportWidth of [320,640,1280]) for(const viewportHeight of [640,900])
        for(const scale of [.5,1,2]) for(const expanded of [false,true]) for(const desiredHeight of [116,400,1000]) {
            const bounds={left:0,right:viewportWidth,top:64,bottom:viewportHeight};
            const size=96*scale;
            for(const [x,y] of [[10,90],[(viewportWidth-size)/2,(viewportHeight-size)/2],[viewportWidth-size-10,viewportHeight-size-10]]) {
                const anchor={left:x,right:x+size,top:y,bottom:y+size};
                const actions=window.workflowNodeActionsPlacement(anchor,bounds,62,28);
                const controls={...actions,right:actions.left+62,bottom:actions.top+28};
                const width=Math.min(expanded?820:428,viewportWidth-20);
                const result=window.workflowInspectorPlacement(anchor,bounds,width,desiredHeight,expanded,36,controls);
                const panel={left:result.left,right:result.left+width,top:result.top,bottom:result.top+result.maxHeight};
                assert.equal(overlaps(panel,anchor),false,'The selected card remains visible');
                assert.equal(overlaps(panel,controls),false,'Run and Apply remain accessible');
                assert.ok(panel.left>=10 && panel.right<=viewportWidth-10);
                assert.ok(panel.top>=74 && panel.bottom<=viewportHeight-10);
                assert.ok(result.maxHeight>0 && result.maxHeight<=desiredHeight);
            }
        }
});
