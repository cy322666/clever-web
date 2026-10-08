import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

test('canvas keeps its dotted grid and uses vertical input ports', () => {
    const css = readFileSync(new URL('../../resources/css/filament-workflows.css',import.meta.url),'utf8');
    assert.equal((css.match(/radial-gradient\(circle/g) ?? []).length,7);
    assert.match(css,/\.workflow-node-port--input::before\s*\{[^}]*width:\s*3px;[^}]*height:\s*14px;/s);
});

test('new branches start from white output handles while existing edges keep hover controls', () => {
    const card = readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflows/action-card.blade.php', import.meta.url), 'utf8');
    const edges = readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflows/edge-controls.blade.php', import.meta.url), 'utf8');
    const css = readFileSync(new URL('../../resources/css/filament-workflows.css', import.meta.url), 'utf8');
    assert.match(card, /workflow-node-port--output-\{\{ \$port \}\}.*startConnection\('action:' \+ @js\(\$actionId\), @js\(\$port\), \$event\)/s);
    assert.match(css, /button\.workflow-node-port--output:hover[^{]*\{[^}]*background:\s*#c5cbd5/s);
    assert.match(edges, /@if\(\$edge\['targetId'\]\)/);
    assert.match(edges, /heroicon-o-plus/);
    assert.match(edges, /heroicon-o-trash/);
    assert.doesNotMatch(edges, /data-workflow-edge-branch|Добавить ещё одну ветку/);
    assert.match(edges, /empty\(\$edge\['branch'\]\)/);
});

test('connected edge controls reveal together and hide after leaving the edge', () => {
    const f = fixture();
    const attributes = () => {
        const values = new Set();
        return {setAttribute:name=>values.add(name),removeAttribute:name=>values.delete(name),has:name=>values.has(name),matches:()=>false};
    };
    const plusAttributes = attributes(), trashAttributes = attributes();
    const trash = {...trashAttributes,hasAttribute:name=>name === 'data-workflow-edge-delete'};
    const plus = {...plusAttributes,nextElementSibling:trash};
    f.canvas.showEdgeControls(plus);
    assert.equal(plus.has('data-workflow-edge-visible'),true);
    assert.equal(trash.has('data-workflow-edge-visible'),true);
    f.canvas.hideEdgeControls(plus);
    assert.equal(plus.has('data-workflow-edge-visible'),false);
    assert.equal(trash.has('data-workflow-edge-visible'),false);
});

test('connected edge buttons and node tools are hover-only on pointer devices', () => {
    const css = readFileSync(new URL('../../resources/css/filament-workflows.css',import.meta.url),'utf8');
    assert.match(css,/\.workflow-node-edge-add\[data-workflow-edge-target\][^{]*\{[^}]*opacity:\s*0;[^}]*pointer-events:\s*none;/s);
    assert.match(css,/\.workflow-node-card--compact:hover\s*>\s*\.workflow-node-card__tools/);
    assert.doesNotMatch(css,/\.workflow-node-card--compact:focus-within\s*>\s*\.workflow-node-card__tools/);
    assert.doesNotMatch(css,/\.workflow-node-card__tools:focus-within/);
    const source = readFileSync(new URL('../../resources/js/app.js',import.meta.url),'utf8');
    assert.match(source,/hit\.addEventListener\('pointerenter', \(\) => this\.showEdgeControls\(control\)\)/);
});

test('edge trash disconnects exactly the selected connection without deleting nodes', async () => {
    const f = fixture();
    const calls = [];
    f.canvas.$wire = {disconnectWorkflowNodes: async (...args) => calls.push(args)};
    f.canvas.selectedEdge = {sourceId:'trigger',sourcePort:'output',targetId:'action:test'};
    await f.canvas.removeSelectedEdge();
    assert.deepEqual(calls, [['trigger','output','action:test']]);
    assert.equal(f.canvas.selectedEdge, null);
});

test('edge trash follows the plus and repeated positions do not mutate styles', () => {
    const source = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
    const code = source.slice(source.indexOf('        const positionControl ='), source.indexOf('        const sourceSelector ='));
    const positionedControls = new Set();
    const position = runInNewContext(code + '\npositionControl;', {positionedControls});
    let writes = 0;
    const style = () => new Proxy({}, {set(target,key,value) {writes++; target[key]=value; return true;}});
    const remove = {hasAttribute:()=>true,style:style()};
    const plus = {style:style(),nextElementSibling:remove};
    position(plus,100,50);
    assert.equal(remove.style.left,'128px');
    assert.equal(remove.style.top,'50px');
    const before = writes;
    position(plus,100,50);
    assert.equal(writes,before);
});

test('another-branch plus is positioned by the card without inventing an edge', () => {
    const source = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
    const code = source.slice(source.indexOf('controls.filter((button) => !button.dataset.workflowEdgeTarget)'), source.indexOf('        controls.forEach((button) => {'));
    const button = {dataset:{workflowEdgeSource:'trigger',workflowEdgeBranch:'true'}, getBoundingClientRect:()=>({width:22})};
    const card = {querySelector:()=>null,getBoundingClientRect:()=>({left:0,right:100,top:0,height:100})};
    const paths = [], positions = [];
    runInNewContext(code, {controls:[button],nodeById:new Map([['trigger',{querySelector:()=>card}]]),sourceSelector:()=>'',stageRect:{left:0,top:0},scale:1,paths,positionControl:(_button,x,y)=>positions.push([x,y]),maxX:0,maxY:0});
    assert.deepEqual(paths, []);
    assert.deepEqual(positions, [[112,78]]);
});

test('an imported layout survives unavailable local storage', () => {
    const f = fixture();
    f.canvas.initialLayout = {'action:test':{x:120,y:80}};
    f.canvas.restoreNodeLayout();
    assert.equal(f.canvas.positions['action:test'].x,120);
    assert.equal(f.canvas.positions['action:test'].y,80);
});

test('dragging a connected ordinary output starts a second branch without replacing the first', () => {
    const f = fixture();
    f.canvas.$refs.edgeControls = {querySelectorAll: () => []};
    f.canvas.$refs.stage = {querySelector: () => ({querySelectorAll: () => [
        {dataset:{workflowEdgeSource:'trigger',workflowEdgePort:'output',workflowEdgeTarget:'action:old'}},
    ]})};
    f.canvas.startConnection('trigger','output',f.event());
    assert.equal(f.canvas.connecting.replaceTarget,null);
    assert.equal(f.captures(),1);
    f.canvas.startBoxSelection(f.event());
    assert.equal(f.canvas.selectionOrigin,null);
});

test('captured plus taps open once and movement does not animate behind the edges', () => {
    const f = fixture();
    let calls=0;
    f.canvas.$wire = {openAddActionOnConnection: () => calls++};
    f.canvas.startEdgeControlDrag('trigger','output',null,f.event());
    assert.equal(f.captures(),1);
    f.canvas.stopCanvasInteraction(f.event());
    f.canvas.openEdgePalette('trigger','output',null);
    assert.equal(calls,1);
    assert.equal(f.events().length, 1);
    assert.equal(f.events()[0].type, 'workflow-node-library-open');
    assert.equal(f.events()[0].detail.mode, 'action');
    const css = readFileSync(new URL('../../resources/css/filament-workflows.css',import.meta.url),'utf8');
    const rule = css.match(/\.workflow-sortable-item\s*\{([^}]+)\}/)[1];
    assert.doesNotMatch(rule,/transition:[^;]*transform/);
});

test('dragging from a connected condition output adds another branch', async () => {
    const f = fixture();
    f.canvas.$refs.edgeControls = {querySelectorAll: () => [{dataset: {workflowEdgeSource:'action:if', workflowEdgePort:'no', workflowEdgeTarget:'action:old'}}]};
    let args;
    f.canvas.$wire = {connectWorkflowNodes: async (...values) => { args = values; }};
    f.canvas.startConnection('action:if','no',f.event());
    f.canvas.stopCanvasInteraction(f.event());
    assert.equal(f.canvas.connecting.replaceTarget, null);
    await f.canvas.completeConnection('action:new');
    assert.deepEqual(args,['action:if','no','action:new',null]);
    assert.equal(f.canvas.connecting,null);
});

function fixture() {
    class Element {}
    let hitElement = null, hitStack = null;
    const events = [];
    const viewportStorage = new Map();
    const layoutStorage = new Map();
    const CustomEvent = class { constructor(type, init = {}) { this.type = type; this.detail = init.detail; } };
    const window = { addEventListener() {}, dispatchEvent(event) { events.push({type:event.type,detail:event.detail}); }, requestAnimationFrame(callback) { callback(); }, setTimeout(callback) { callback(); return 1; }, clearTimeout() {} };
    window.sessionStorage = {getItem: key => viewportStorage.get(key) ?? null, setItem: (key, value) => viewportStorage.set(key, value)};
    window.localStorage = {getItem: key => layoutStorage.get(key) ?? null, setItem: (key, value) => layoutStorage.set(key, value)};
    let mutationCallback, observedOptions, resizeCallback;
    const resizedElements = new Set();
    runInNewContext(readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8'), {
        window, document: { addEventListener() {}, elementsFromPoint() { return hitStack || [hitElement]; }, elementFromPoint() { return hitElement; }, createElementNS() {
            return {attributes:{}, dataset:{}, listeners:{}, classList:{add(){}},
                setAttribute(key,value) {this.attributes[key]=value;}, appendChild(){},
                addEventListener(key,fn) {this.listeners[key]=fn;}};
        } }, Element, CustomEvent,
        MutationObserver: class {
            constructor(callback) { mutationCallback = callback; }
            disconnect() {}
            observe(element, options) { observedOptions = options; }
        },
        ResizeObserver: class {
            constructor(callback) { resizeCallback = callback; }
            disconnect() { resizedElements.clear(); }
            observe(element) { resizedElements.add(element); }
            unobserve(element) { resizedElements.delete(element); }
        },
    });
    const node = { dataset: { workflowNodeId: 'action:test' }, style: {}, children: [], classList: { add() {}, remove() {} }, querySelector() { return null; } };
    const target = new Element();
    const card = { closest: () => node };
    target.closest = (selector) => selector === '[data-workflow-node-id]' ? node : selector === '[data-workflow-node-card]' ? card : null;
    const canvas = window.workflowNodeCanvas();
    canvas.nodeElements = () => [node];
    canvas.$nextTick = callback => callback ? callback() : Promise.resolve();
    let captures = 0;
    let saves = 0;
    canvas.$refs = { viewport: { setPointerCapture() { captures++; }, releasePointerCapture() {}, classList: node.classList } };
    canvas.scheduleGraphRefresh = () => {};
    canvas.scheduleGraphRefreshAfterPaint = () => {};
    canvas.saveNodeLayout = () => { saves++; };
    const event = (x = 10, y = 20) => ({ target, pointerId: 1, button: 0, clientX: x, clientY: y, prevented: false, preventDefault() { this.prevented = true; } });

    return { canvas, workbench: window.workflowWorkbench(), rawCanvas: window.workflowNodeCanvas, node, target, event, viewportStorage, layoutStorage, mutate: (records) => mutationCallback(records), resize: () => resizeCallback(), resizedElements, observedOptions: () => observedOptions, setHit: (el) => {hitElement = el;}, setHitStack: els => {hitStack = els;}, captures: () => captures, saves: () => saves, events: () => events };
}

test('alignment follows graph connections instead of action array order and separates branches', () => {
    const f = fixture();
    const ids = ['trigger', 'c', 'b', 'a', 'yes1', 'no1', 'yes2', 'join', 'detached'];
    const edges = [
        ['trigger','a'], ['a','b'], ['b','c'], ['c','no1','no'], ['c','yes1','yes'],
        ['yes1','yes2'], ['yes2','join'], ['no1','join'],
    ].map(([source,target,port='output']) => ({source,target,port}));
    const layout = f.canvas.graphLayout(ids,edges);
    for (const edge of edges) assert.ok(layout[edge.target].x > layout[edge.source].x);
    assert.equal(layout.a.y,layout.b.y);
    assert.ok(layout.yes1.y < layout.no1.y);
    assert.equal(layout.yes1.y,layout.yes2.y);
    assert.ok(Math.abs(layout.yes1.y-layout.no1.y) >= 208);
    assert.ok(layout.detached.y > layout.no1.y);
    assert.equal(JSON.stringify(layout),JSON.stringify(f.canvas.graphLayout(ids,edges)), 'Repeated alignment is stable');
    const copy = JSON.stringify(edges);
    f.canvas.graphLayout(ids,edges);
    assert.equal(JSON.stringify(edges),copy,'Alignment must not change connections');
});

test('alignment handles merging starts, duplicate edges and rejects cycles without corrupting positions', () => {
    const f=fixture();
    const edges=[{source:'trigger',target:'a'},{source:'trigger:2',target:'a'},{source:'a',target:'b'}];
    const points=f.canvas.graphLayout(['trigger','trigger:2','a','b'],[...edges,edges[0],{source:'missing',target:'a'}]);
    assert.equal(Object.keys(points).length,4);
    assert.notEqual(points.trigger.y,points['trigger:2'].y);
    assert.ok(points.a.x>points['trigger:2'].x);
    assert.equal(f.canvas.graphLayout(['a','b'],[{source:'a',target:'b'},{source:'b',target:'a'}]),null);
});

test('align applies and persists real coordinates, then fits without resetting to the DOM row', () => {
    const f=fixture();
    let points, fits=0;
    f.canvas.$refs.edgeControls={querySelectorAll:()=>[]};
    f.canvas.restoreCardPositions=value=>{points=value;};
    f.canvas.fitView=()=>fits++;
    f.canvas.resetNodeLayout();
    assert.equal(points['action:test'].x,48);
    assert.equal(points['action:test'].y,48);
    assert.equal(fits,1);
});

test('completed edges include the selected false branch but never pending, failed or unselected paths', () => {
    const f=fixture();
    f.canvas.nodeElements=()=>[{dataset:{workflowNodeId:'trigger'}}];
    f.canvas.executionState={results:[
        {id:'a',status:'completed',output:{passed:false}},
        {id:'b',status:'completed'}, {id:'failed',status:'error'}, {id:'sim',status:'simulated'},
    ]};
    const edge=(sourceId,targetId,sourcePort='output')=>({sourceId,targetId,sourcePort});
    assert.equal(f.canvas.edgeCompleted(edge('trigger','action:a')),true);
    assert.equal(f.canvas.edgeCompleted(edge('action:a','action:b','no')),true);
    assert.equal(f.canvas.edgeCompleted(edge('action:a','action:b','yes')),false);
    assert.equal(f.canvas.edgeCompleted(edge('action:a','action:missing')),false);
    assert.equal(f.canvas.edgeCompleted(edge('action:a','action:failed')),false);
    assert.equal(f.canvas.edgeCompleted(edge('action:failed','action:b')),false);
    assert.equal(f.canvas.edgeCompleted(edge('action:sim','action:b')),false);
    assert.equal(f.canvas.edgeCompleted(edge('action:a',null)),false);
    assert.equal(f.canvas.edgeCompleted(edge('action:a','action:b'),'false'),false);
    f.canvas.executionState={results:[]};
    assert.equal(f.canvas.edgeCompleted(edge('trigger','action:a')),false);
});

test('execution changes redraw arrows and the canvas controls stay large on the right without zoom buttons', () => {
    const f=fixture(); let refreshes=0;
    f.canvas.scheduleGraphRefresh=()=>refreshes++;
    f.canvas.setExecutionState({results:[]});
    assert.equal(refreshes,1);
    const blade=readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflow-builder.blade.php',import.meta.url),'utf8');
    const css=readFileSync(new URL('../../resources/css/filament-workflows.css',import.meta.url),'utf8');
    assert.doesNotMatch(blade,/aria-label="(?:Увеличить|Уменьшить) масштаб"/);
    assert.match(css,/\.workflow-canvas-controls \{[^}]*right: 16px;[^}]*top: 68px;/);
    assert.match(css,/\.workflow-canvas-controls \.workflow-canvas-fit \{[^}]*width: 44px; height: 44px;/);
    assert.match(css,/\.workflow-node-card__tools button \{ flex: 0 0 1\.5rem;/);
    assert.match(css,/\.workflow-node-edge\.workflow-node-edge--completed \{ stroke: #4d9c70 !important;/);
});

test('structural edits rebase relative offsets and retain absolute card positions', () => {
    const f = fixture();
    let base = 300;
    f.canvas.scale = 0.5;
    f.canvas.$refs.stage = {getBoundingClientRect:()=>({left:100,top:50})};
    f.node.getBoundingClientRect = () => ({left:100+(base+(f.canvas.positions['action:test']?.x||0))*0.5,top:50+80*0.5});
    f.canvas.positions = {'action:test':{x:40,y:0}};
    const before = f.canvas.cardPositions();
    base += 224; // A new sibling changed the flex base, not the user's position.
    assert.equal(f.canvas.restoreCardPositions(before),true);
    assert.equal(f.canvas.positions['action:test'].x,-184);
    assert.equal(f.canvas.cardPositions()['action:test'].x,340);
    assert.equal(f.saves(),1);
    assert.equal(f.canvas.restoreCardPositions(before),false);
    assert.equal(f.saves(),1, 'An unchanged modal close must not dirty the workflow');
    base -= 448; // Deletion/flattening changes the base in the other direction.
    f.canvas.restoreCardPositions(before);
    assert.equal(f.canvas.cardPositions()['action:test'].x,340);
});

test('Ctrl/Cmd copy and paste preserve group spacing, wait for copy, and ignore text editing', async () => {
    const f = fixture();
    const calls = [], placed = [];
    f.canvas.$el = {isConnected:true,getClientRects:()=>[{}]};
    f.canvas.selectedNodeIds = ['action:a','action:b'];
    f.canvas.cardPositions = () => ({'action:a':{x:20,y:30},'action:b':{x:220,y:90}});
    f.canvas.restoreCardPositions = positions => placed.push(JSON.parse(JSON.stringify(positions)));
    f.canvas.$wire = {copyWorkflowNodes:async ids=>{calls.push([...ids]);return true;},pasteWorkflowNodes:async()=>({'action:a':'action:c','action:b':'action:d'})};
    const event = (code, metaKey=false, editing=false) => ({code,ctrlKey:!metaKey,metaKey,target:{closest:()=>editing},preventDefault(){this.prevented=true;}});
    const copy = event('KeyC'), paste = event('KeyV',true);
    await Promise.all([f.canvas.clipboardShortcut(copy),f.canvas.clipboardShortcut(paste)]);
    assert.equal(copy.prevented,true);
    assert.equal(paste.prevented,true);
    assert.deepEqual(calls,[['action:a','action:b']]);
    assert.deepEqual(placed[0],{'action:c':{x:100,y:110},'action:d':{x:300,y:170}});
    assert.deepEqual([...f.canvas.selectedNodeIds],['action:c','action:d']);
    const inputPaste = event('KeyV',true,true);
    await f.canvas.clipboardShortcut(inputPaste);
    assert.equal(inputPaste.prevented,undefined);
    assert.equal(placed.length,1);
    await f.canvas.clipboardShortcut(event('KeyV'));
    assert.equal(placed[1]['action:c'].x,180);
});

test('dropping an output or plus on empty canvas opens insertion there, including Yes and No', () => {
    for (const port of ['output','yes','no']) {
        const f = fixture();
        const calls = [];
        f.canvas.$wire = {openAddActionOnConnection: (...args) => calls.push(args)};
        f.canvas.$refs.viewport.getBoundingClientRect = () => ({left:0,top:0,right:1000,bottom:800});
        f.canvas.$refs.stage = {getBoundingClientRect: () => ({left:100,top:50}),querySelectorAll:()=>[]};
        f.node.getBoundingClientRect = () => ({left:110,top:60});
        f.canvas.scale = .5;
        f.canvas.startConnection('action:test', port, f.event());
        f.canvas.stopCanvasInteraction(f.event(400,300));
        assert.deepEqual(calls, [['action:test',port,null]]);
        assert.equal(f.canvas.connecting, null);
        assert.equal(f.canvas.pendingInsertion.dropPosition.x,600);
        assert.equal(f.canvas.pendingInsertion.dropPosition.y,500);
        assert.equal(f.events()[0].detail.mode,'action');
    }
});

test('drop outside the canvas or pointer cancel never opens the catalogue', () => {
    for (const type of ['pointerup','pointercancel']) {
        const f = fixture();
        f.canvas.$refs.viewport.getBoundingClientRect = () => ({left:0,top:0,right:100,bottom:100});
        f.canvas.$refs.stage = {getBoundingClientRect: () => ({left:0,top:0})};
        f.canvas.$wire = {openAddActionOnConnection: () => assert.fail('must not insert')};
        f.canvas.startConnection('action:test','output',f.event());
        f.canvas.stopCanvasInteraction({...f.event(400,300),type});
        assert.equal(f.canvas.connecting,null);
        assert.equal(f.canvas.pendingInsertion,null);
    }
});

test('a dropped node uses the release point without moving unrelated nodes', () => {
    const f = fixture();
    const boxes = {source:{x:0,y:0},unrelated:{x:500,y:300},inserted:{x:900,y:0}};
    f.canvas.nodeElements = () => Object.entries(boxes).map(([id,p]) => ({dataset:{workflowNodeId:id},style:{},children:[],querySelector:()=>null,getBoundingClientRect:()=>({left:p.x,top:p.y})}));
    f.canvas.$refs.stage = {getBoundingClientRect:()=>({left:0,top:0})};
    f.canvas.pendingInsertion = {sourceId:'source',targetId:null,nodes:{source:boxes.source,unrelated:boxes.unrelated},edges:[],dropPosition:{x:700,y:400}};
    f.canvas.placeInsertedNode({sourceId:'source',targetId:null,nodeId:'inserted'});
    assert.equal(boxes.inserted.x+f.canvas.positions.inserted.x,652);
    assert.equal(boxes.inserted.y+f.canvas.positions.inserted.y,352);
    assert.equal(f.canvas.positions.unrelated?.x ?? 0,0);
});

test('save state changes only for persisted edits and resets to clean on acknowledgement', () => {
    const f = fixture();
    const wire = {data:{name:'Поток',group_name:null},definition:{trigger:{type:'manual'},actions:[]},trigger:{type:'manual'},workflowActions:[]};
    f.workbench.$wire = wire;
    assert.equal(f.workbench.hasUnsavedChanges,true,'new unsaved workflow');
    const acknowledge = () => wire.savedWorkflowEditorState = structuredClone({name:wire.data.name,group_name:null,definition:{...wire.definition,trigger:wire.trigger,actions:wire.workflowActions}});
    acknowledge();
    assert.equal(f.workbench.hasUnsavedChanges,false);
    wire.definition = {actions:[],trigger:{type:'manual'}};
    wire.debugState = {step:1};
    assert.equal(f.workbench.hasUnsavedChanges,false,'key order and debugger are not edits');
    wire.workflowActions.push({id:'one',config:{name:'Сделка'}});
    assert.equal(f.workbench.hasUnsavedChanges,true);
    acknowledge();
    assert.equal(f.workbench.hasUnsavedChanges,false);
    wire.workflowActions[0].config.name = 'Другая';
    assert.equal(f.workbench.hasUnsavedChanges,true);
    wire.workflowActions[0].config.name = 'Сделка';
    assert.equal(f.workbench.hasUnsavedChanges,false,'reverting to saved values is clean');
});

test('node positions update deferred save data immediately, unlike viewport panning', () => {
    const f = fixture();
    const calls = [];
    f.canvas.$wire = {$set:(...args)=>calls.push(args)};
    f.canvas.positions = {'action:test':{x:10,y:20},removed:{x:40,y:30}};
    f.rawCanvas().saveNodeLayout.call(f.canvas);
    assert.equal(calls[0][0],'definition.canvas_layout');
    assert.equal(calls[0][1]['action:test'].x,10);
    assert.equal(calls[0][1].removed,undefined);
    assert.equal(calls[0][2],false);
    assert.equal(JSON.parse(f.layoutStorage.get(f.canvas.layoutKey)).nodes['action:test'].x,10);
});

test('reopening uses server coordinates, never an empty or stale browser snapshot', () => {
    const f = fixture();
    f.canvas.initialLayout = {'action:test':{x:-197,y:346.3}};
    for (const nodes of [{}, {'action:test':{x:0,y:0}}]) {
        f.layoutStorage.set(f.canvas.layoutKey, JSON.stringify({version:1,nodes}));
        f.canvas.restoreNodeLayout();
        assert.equal(f.canvas.positions['action:test'].x,-197);
        assert.equal(f.canvas.positions['action:test'].y,346.3);
    }
    f.canvas.initialLayout = [];
    f.canvas.restoreNodeLayout();
    assert.deepEqual(Object.keys(f.canvas.positions), [], 'explicit server reset must not revive old positions');
});

test('legacy browser-only coordinates are captured before Save without requiring another drag', () => {
    const f = fixture();
    const calls = [];
    f.canvas.$wire = {$set:(...args)=>calls.push(args)};
    f.layoutStorage.set(f.canvas.layoutKey, JSON.stringify({version:1,nodes:{'action:test':{x:42,y:-155}}}));
    f.canvas.restoreNodeLayout();
    f.rawCanvas().saveNodeLayout.call(f.canvas);
    assert.equal(calls[0][1]['action:test'].y,-155);
    f.layoutStorage.clear(); // Same saved workflow, another browser.
    f.canvas.initialLayout = calls[0][1];
    f.canvas.restoreNodeLayout();
    assert.equal(f.canvas.positions['action:test'].x,42);
    const view = readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflow-builder.blade.php', import.meta.url), 'utf8');
    assert.match(view,/x-on:submit.window.capture="if \(\$event.target.contains\(\$el\)\) saveNodeLayout\(\)"/);
    assert.doesNotMatch(view,/selectionMode|Выделить несколько нод/);
});

test('plain drag on empty canvas draws a fresh selection without a mode button or panning', () => {
    const f = fixture();
    f.target.closest = () => null;
    f.node.getBoundingClientRect = () => ({left:20,top:30,right:70,bottom:80});
    f.canvas.$refs.viewport.getBoundingClientRect = () => ({left:0,top:0});
    f.canvas.selectedNodeIds = ['old-selection'];
    f.canvas.startCanvasInteraction(f.event(0,0));
    f.canvas.moveCanvas(f.event(100,100));
    assert.deepEqual([...f.canvas.selectedNodeIds], ['action:test']);
    assert.equal(f.canvas.translateX, 0);
    assert.equal(f.canvas.translateY, 0);
    f.canvas.stopCanvasInteraction(f.event(100,100));
    assert.equal(f.canvas.selectionBox, null);
});

test('Shift, Ctrl and Cmd clicks toggle membership without opening or dragging a node', () => {
    for (const modifier of ['shiftKey', 'ctrlKey', 'metaKey']) {
        const f = fixture();
        f.canvas.$refs.stage = {contains: () => true};
        f.canvas.selectedNodeIds = ['action:another'];
        const down = {...f.event(), [modifier]:true};
        f.canvas.startCanvasInteraction(down);
        assert.deepEqual([...f.canvas.selectedNodeIds], ['action:another', 'action:test']);
        assert.equal(f.canvas.draggingNodeId, null);
        f.canvas.suppressClickUntil = 0; // Holding the modifier must not time out.
        const click = {...down, prevented:false, stopImmediatePropagation(){}, stopPropagation(){}};
        f.canvas.suppressNodeClick(click);
        assert.equal(click.prevented, true);
        f.canvas.startCanvasInteraction(down);
        assert.deepEqual([...f.canvas.selectedNodeIds], ['action:another']);
    }
});

test('captions drag the whole selected group and suppress only the trailing drag click', () => {
    const f = fixture();
    const closest = f.target.closest;
    f.target.closest = selector => selector.includes('.workflow-node-card__caption') ? {} : closest(selector);
    f.canvas.selectedNodeIds = ['action:test', 'action:second'];
    f.canvas.startNodeDrag(f.event(), f.node);
    f.canvas.moveCanvas(f.event(50,70));
    assert.equal(f.canvas.positions['action:second'].x, 40);
    f.canvas.stopCanvasInteraction(f.event(50,70));
    const click = {...f.event(), stopImmediatePropagation(){}, stopPropagation(){}};
    f.canvas.suppressNodeClick(click);
    assert.equal(click.prevented, true);
});

test('wheel and trackpad pan both axes, Shift pans horizontally, and line units normalize', () => {
    const f = fixture();
    const wheel = values => ({...f.event(),deltaX:0,deltaY:0,deltaMode:0,...values});
    const event = wheel({deltaX:30,deltaY:60});
    f.canvas.onCanvasWheel(event);
    assert.equal(event.prevented, true);
    assert.equal(f.canvas.translateX, -30);
    assert.equal(f.canvas.translateY, -60);
    f.canvas.onCanvasWheel(wheel({deltaY:2,deltaMode:1,shiftKey:true}));
    assert.equal(f.canvas.translateX, -62);
    assert.equal(f.canvas.translateY, -60);
    assert.equal(f.canvas.scale, 1);
    f.canvas.$refs.viewport.clientHeight = 600;
    f.canvas.onCanvasWheel(wheel({deltaY:1,deltaMode:2}));
    assert.equal(f.canvas.translateY, -660);
});

test('pinch zoom is anchored to the pointer and ordinary wheel ignores text areas', () => {
    const f = fixture();
    f.canvas.$refs.viewport.getBoundingClientRect = () => ({left:20,top:30});
    const zoom = {...f.event(220,180),ctrlKey:true,deltaX:0,deltaY:-20};
    f.canvas.onCanvasWheel(zoom);
    assert.ok(f.canvas.scale > 1);
    assert.ok(Math.abs((200-f.canvas.translateX)/f.canvas.scale - 200) < 1e-9);
    assert.ok(Math.abs((150-f.canvas.translateY)/f.canvas.scale - 150) < 1e-9);
    const before = f.canvas.translateY;
    f.target.closest = () => ({});
    const textWheel = {...f.event(),deltaX:0,deltaY:200};
    f.canvas.onCanvasWheel(textWheel);
    assert.equal(textWheel.prevented, false);
    assert.equal(f.canvas.translateY, before);
});

test('canvas reinitialization never recenters and viewport storage is scoped to the workflow', () => {
    const f = fixture();
    f.canvas.observeCanvas = () => {};
    f.canvas.translateX = -400;
    f.canvas.translateY = 70;
    f.canvas.scale = .65;
    f.canvas.saveViewport();
    f.canvas.translateX = 0;
    let centers = 0;
    f.canvas.centerView = () => centers++;
    f.canvas.initializeCanvas();
    assert.equal(f.canvas.translateX, -400);
    assert.equal(f.canvas.translateY, 70);
    assert.equal(f.canvas.scale, .65);
    f.canvas.initializeCanvas();
    assert.equal(centers, 0);
    f.canvas.layoutKey = 'another-workflow';
    assert.equal(f.canvas.restoreViewport(), false);
    f.viewportStorage.set('another-workflow:viewport', '{"x":null,"y":0,"scale":0}');
    assert.equal(f.canvas.restoreViewport(), false);
});

test('both condition output stubs render separate paths and visible plus controls', () => {
    const f = fixture();
    const card = {querySelector: () => null, getBoundingClientRect: () => ({left:0,top:0,right:100,width:100,height:100})};
    f.node.querySelector = () => card;
    f.canvas.$refs.stage = {querySelector: () => null, getBoundingClientRect: () => ({left:0,top:0})};
    const controls = ['yes','no'].map(port => ({dataset:{workflowEdgeSource:'action:test',workflowEdgePort:port},style:{}}));
    f.canvas.$refs.edgeControls = {querySelectorAll: () => controls};
    let elements;
    f.canvas.$refs.edgeLayer = {style:{},setAttribute(){},replaceChildren(...items){elements=items;}};
    f.canvas.drawConnections();
    assert.equal(elements.filter(e => e.attributes.class === 'workflow-node-edge-hit').length, 2);
    assert.ok(controls.every(c => c.style.visibility === 'visible'));
    assert.notEqual(controls[0].style.top, controls[1].style.top);
});

test('zoom buttons preserve the viewport center, layout and zoom limits', () => {
    const f = fixture();
    f.canvas.$refs.viewport = {clientWidth:800,clientHeight:600,scrollLeft:30,scrollTop:20};
    f.canvas.translateX = 80; f.canvas.translateY = 50; f.canvas.scale = .5;
    const center = () => [(430-f.canvas.translateX)/f.canvas.scale, (320-f.canvas.translateY)/f.canvas.scale];
    const before = center();
    f.canvas.positions = {'action:test':{x:100,y:50}};
    f.canvas.zoomCanvas(1.2);
    assert.equal(f.canvas.scale,.6);
    assert.deepEqual(center(),before);
    assert.equal(f.canvas.positions['action:test'].x,100);
    f.canvas.zoomCanvas(100); assert.equal(f.canvas.scale,2);
    f.canvas.zoomCanvas(.0001); assert.equal(f.canvas.scale,.01);
});

test('dropping through an overlapping SVG edge reaches the detached card', async () => {
    const f = fixture();
    f.canvas.$refs.stage = {contains: () => true};
    f.setHitStack([{closest: () => null}, f.target]);
    let connected;
    f.canvas.$wire = {connectWorkflowNodes: async (...args) => {connected = args;}};
    f.canvas.startEdgeControlDrag('trigger', 'output', null, f.event());
    f.canvas.moveCanvas({...f.event(50, 70), button: -1});
    f.canvas.stopCanvasInteraction(f.event(50, 70));
    await Promise.resolve();
    assert.deepEqual(connected, ['trigger', 'output', 'action:test', null]);
});

test('small zoomed inputs accept nearby releases but not distant empty wrapper space', () => {
    const f = fixture();
    f.canvas.connecting = {sourceId: 'trigger'};
    f.node.querySelector = () => ({getBoundingClientRect: () => ({left: 100, top: 100, width: 5, height: 5})});
    assert.equal(f.canvas.connectionTargetAt(f.event(93, 102)), 'action:test');
    assert.equal(f.canvas.connectionTargetAt(f.event(75, 102)), null);
    f.canvas.connecting.sourceId = 'action:test';
    assert.equal(f.canvas.connectionTargetAt(f.event(102, 102)), null);
});

test('attribute-only Livewire resets restore node positions and schedule edge controls', () => {
    const f = fixture();
    const svg = {contains: target => target === svg};
    f.canvas.$refs.stage = {querySelector: () => svg, querySelectorAll: () => []};
    f.canvas.nodeElements = () => [f.node];
    f.canvas.positions['action:test'] = {x: 40, y: 180};
    let refreshes = 0;
    f.canvas.scheduleGraphRefreshAfterPaint = () => refreshes++;
    f.canvas.observeCanvas();
    assert.equal(f.observedOptions().attributes, true);
    assert.ok(f.observedOptions().attributeFilter.includes('style'));
    f.mutate([{type:'attributes',attributeName:'style',target:f.node}]);
    assert.equal(f.node.style.transform, 'translate3d(40px, 180px, 0px)');
    assert.equal(refreshes, 1);
    f.mutate([{type:'attributes',target:svg}]);
    assert.equal(refreshes, 1, 'Drawing SVG edges must not trigger another refresh');
});

test('browser-normalized translations do not cause mutation redraw loops', () => {
    const f = fixture();
    let writes = 0;
    f.node.style = {get transform() { return 'translate3d(40.1235px, 180px, 0px)'; }, set transform(value) { writes++; }};
    f.canvas.applyNodePosition(f.node, {x:40.123456, y:180});
    assert.equal(writes, 0);
});

test('new and resized card ports redraw edges after layout settles', () => {
    const f = fixture();
    const first = {}, added = {};
    let geometry = [first], refreshes = 0;
    f.canvas.$refs.stage = {querySelector: () => null, querySelectorAll: () => geometry};
    f.canvas.scheduleGraphRefreshAfterPaint = () => refreshes++;
    f.canvas.observeCanvas();
    assert.ok(f.resizedElements.has(first));
    geometry = [added];
    f.mutate([{type:'childList',target:f.canvas.$refs.stage}]);
    assert.ok(f.resizedElements.has(added));
    assert.equal(f.resizedElements.has(first), false);
    f.resize();
    assert.equal(refreshes, 2);
});

test('each unsaved editor gets a stable draft layout key, not a shared request URL', () => {
    const blade = readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflow-builder.blade.php', import.meta.url), 'utf8');
    assert.match(blade, /'draft:' \. \$this->getId\(\)/);
    assert.doesNotMatch(blade, /'draft:' \. request\(\)->path\(\)/);
    assert.match(blade, /wire:key="workflow-start-\{\{ \$startId \}\}"/);
});

test('editing coordinates and debugging never rewrite Alpine canvas lifecycle attributes', () => {
    const blade = readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflow-builder.blade.php', import.meta.url), 'utf8');
    assert.match(blade, /wire:key="workflow-canvas-\{\{ \$workflowLayoutKey \}\}"/);
    assert.match(blade, /x-data="workflowNodeCanvas\(@js\(\$workflowLayoutKey\), \$wire\.definition\.canvas_layout \?\? null\)"/);
    assert.match(blade, /setExecutionState\(\$wire\.debugState\)/);
    assert.doesNotMatch(blade, /@js\(\$this->(?:definition\['canvas_layout'\]|debugState)/);
});

test('Shift rectangle selects several nodes and group drag preserves relative spacing at zoom', () => {
    const f = fixture();
    const second = {...f.node, dataset:{workflowNodeId:'action:second'},style:{},getBoundingClientRect:()=>({left:100,top:40,right:140,bottom:80})};
    f.node.getBoundingClientRect=()=>({left:20,top:40,right:60,bottom:80});
    f.canvas.nodeElements=()=>[f.node,second];
    f.canvas.$refs.viewport.getBoundingClientRect=()=>({left:0,top:0});
    f.canvas.startBoxSelection({...f.event(0,0),shiftKey:true});
    f.canvas.moveCanvas(f.event(160,100));
    assert.deepEqual([...f.canvas.selectedNodeIds],['action:test','action:second']);
    f.canvas.stopCanvasInteraction(f.event(160,100));
    assert.equal(f.canvas.selectionBox,null);
    f.canvas.scale=.5;
    f.canvas.positions={'action:test':{x:10,y:20},'action:second':{x:90,y:20}};
    f.canvas.startNodeDrag(f.event(30,50),f.node);
    f.canvas.moveCanvas(f.event(50,80));
    assert.equal(f.canvas.positions['action:test'].x,50);
    assert.equal(f.canvas.positions['action:second'].x,130);
    assert.equal(f.canvas.positions['action:second'].y,80);
    f.canvas.stopCanvasInteraction(f.event(50,80));
    assert.equal(f.saves(),1);
    f.canvas.clearNodeSelection();
    assert.equal(f.canvas.selectedNodeIds.length,0);
});

test('inserting between nodes creates space downstream while preserving unrelated nodes', () => {
    const f = fixture();
    const boxes={source:{x:0,y:0},target:{x:192,y:0},tail:{x:384,y:0},unrelated:{x:500,y:300},inserted:{x:900,y:0}};
    const nodes=Object.entries(boxes).map(([id,p])=>({dataset:{workflowNodeId:id},style:{},children:[],querySelector:()=>null,getBoundingClientRect:()=>({left:p.x,top:p.y})}));
    f.canvas.nodeElements=()=>nodes;
    f.canvas.$refs.stage={getBoundingClientRect:()=>({left:0,top:0})};
    f.canvas.pendingInsertion={sourceId:'source',targetId:'target',nodes:{source:boxes.source,target:boxes.target,tail:boxes.tail,unrelated:boxes.unrelated},edges:[{source:'target',target:'tail'}]};
    f.canvas.placeInsertedNode({sourceId:'source',targetId:'target',nodeId:'inserted'});
    assert.equal(boxes.inserted.x+f.canvas.positions.inserted.x,192);
    assert.equal(boxes.target.x+f.canvas.positions.target.x,384);
    assert.equal(boxes.tail.x+f.canvas.positions.tail.x,576);
    assert.equal(f.canvas.positions.unrelated?.x ?? 0,0);
    assert.equal(f.canvas.positions.unrelated?.y ?? 0,0);
    assert.equal(f.saves(),1);
});

test('clicking plus still opens the palette, dragging plus connects to a detached card', async () => {
    const f = fixture();
    let added = 0, connected;
    f.canvas.$wire = {openAddActionOnConnection: () => added++, connectWorkflowNodes: async (...args) => {connected=args;}};
    f.canvas.startEdgeControlDrag('action:company','output',null,f.event());
    f.canvas.moveCanvas(f.event(12,21));
    f.canvas.stopCanvasInteraction(f.event(12,21));
    f.canvas.openEdgePalette('action:company','output',null);
    assert.equal(added,1);
    assert.equal(f.canvas.connecting,null);
    f.canvas.startEdgeControlDrag('action:company','output',null,f.event());
    f.canvas.moveCanvas({...f.event(50,70), button:-1, type:'pointermove'});
    f.canvas.$refs.stage = {contains: () => true};
    f.setHit(f.target);
    f.canvas.stopCanvasInteraction(f.event(50,70));
    await Promise.resolve();
    assert.deepEqual(connected,['action:company','output','action:test',null]);
    f.canvas.openEdgePalette('action:company','output',null);
    assert.equal(added,1,'Trailing drag click must not open the palette');
    assert.equal(f.canvas.pendingEdgeDrag,null);
});

test('dragging an attached plus retains its replacement target and cancel never changes edges', () => {
    const f = fixture();
    f.canvas.startEdgeControlDrag('action:if','no','action:old',f.event());
    f.canvas.moveCanvas(f.event(70,90));
    assert.equal(f.canvas.connecting.replaceTarget,'action:old');
    f.canvas.stopCanvasInteraction({...f.event(),type:'pointercancel'});
    assert.equal(f.canvas.connecting,null);
    assert.equal(f.canvas.pendingEdgeDrag,null);
});

test('the visible unconnected start stub starts a new connection, not a reconnect', async () => {
    const f = fixture();
    f.node.dataset.workflowNodeId = 'trigger:timer';
    const port = {getBoundingClientRect: () => ({left:90, top:40, width:10, height:10})};
    const card = {querySelector: () => port};
    f.node.querySelector = () => card;
    f.canvas.nodeElements = () => [f.node];
    f.canvas.$refs.stage = {querySelector: () => null, getBoundingClientRect: () => ({left:0,top:0})};
    const control = {dataset:{workflowEdgeSource:'trigger:timer',workflowEdgePort:'output'},style:{}};
    f.canvas.$refs.edgeControls = {querySelectorAll: () => [control]};
    let elements;
    f.canvas.$refs.edgeLayer = {style:{},setAttribute(){},replaceChildren(...items){elements=items;}};
    f.canvas.drawConnections();
    assert.equal(control.style.visibility, 'visible');
    control.style = {};
    f.canvas.drawConnections();
    assert.equal(control.style.visibility, 'visible', 'Plus must recover after Livewire removes inline styles');
    assert.ok(control.style.left && control.style.top);
    let styleWrites = 0;
    control.style = new Proxy(control.style, {set(target,key,value) { styleWrites++; target[key]=value; return true; }});
    f.canvas.drawConnections();
    assert.equal(styleWrites, 0, 'An unchanged plus must not generate another mutation refresh');
    const hit = elements.find(e => e.attributes.class === 'workflow-node-edge-hit');
    assert.ok(hit, 'A free output must have an interactive line');
    hit.listeners.pointerdown({...f.event(),stopPropagation(){}});
    assert.equal(f.canvas.connecting.sourceId, 'trigger:timer');
    assert.equal(f.canvas.connecting.replaceTarget, null);
    assert.equal(f.canvas.selectedEdge, null);
    let args;
    f.canvas.$wire = {connectWorkflowNodes: async (...values) => {args=values;}};
    await f.canvas.completeConnection('action:existing');
    assert.deepEqual(args,['trigger:timer','output','action:existing',null]);
});

test('a click, including a small pointer jitter, is not captured or suppressed', () => {
    const f = fixture();
    const down = f.event();
    f.canvas.startNodeDrag(down, f.node);
    f.canvas.moveCanvas(f.event(12, 21));
    f.canvas.stopCanvasInteraction(f.event(12, 21));
    assert.equal(down.prevented, false);
    assert.equal(f.captures(), 0);
    assert.equal(f.saves(), 0);
    assert.equal(f.canvas.suppressedNodeId, null);
    assert.equal(Object.keys(f.canvas.positions).length, 0);
});

test('a detached node is initially placed away from the preceding output stub', () => {
    const f = fixture();
    f.node.dataset.workflowDetached = 'true';
    f.canvas.nodeElements = () => [f.node];
    f.canvas.applyNodePositions();
    assert.equal(f.canvas.positions['action:test'].y, 180);
    f.canvas.positions['action:test'].y = 210;
    f.canvas.applyNodePositions();
    assert.equal(f.canvas.positions['action:test'].y, 210);
});

test('a drag captures after the threshold, saves the position and suppresses its trailing click', () => {
    const f = fixture();
    f.canvas.startNodeDrag(f.event(), f.node);
    f.canvas.moveCanvas(f.event(40, 60));
    f.canvas.moveCanvas(f.event(50, 70));
    assert.equal(f.captures(), 1);
    assert.equal(f.canvas.positions['action:test'].x, 40);
    assert.equal(f.canvas.positions['action:test'].y, 50);
    f.canvas.stopCanvasInteraction(f.event(50, 70));
    assert.equal(f.saves(), 1);
    const click = { ...f.event(), stopImmediatePropagation() {}, stopPropagation() {} };
    f.canvas.suppressNodeClick(click);
    assert.equal(click.prevented, true);
    assert.equal(f.canvas.suppressedNodeId, null);
});

test('empty space inside the node wrapper never starts a node drag', () => {
    const f = fixture();
    f.target.closest = (selector) => selector === '[data-workflow-node-id]' ? f.node : null;
    f.canvas.startNodeDrag(f.event(), f.node);
    f.canvas.moveCanvas(f.event(90, 90));
    assert.equal(f.canvas.draggingNodeId, null);
    assert.equal(f.captures(), 0);
    assert.deepEqual(Object.keys(f.canvas.positions), []);
});

test('reconnecting an existing edge preserves the old endpoint until the replacement is accepted', async () => {
    const f = fixture();
    const calls = [];
    f.canvas.$wire = {connectWorkflowNodes: async (...args) => calls.push(args)};
    const edge = {sourceId:'action:if',sourcePort:'no',targetId:'action:old'};
    f.canvas.beginEdgeReconnect(edge, f.event());
    f.canvas.startCanvasInteraction(f.event());
    assert.equal(f.canvas.draggingNodeId,null);
    assert.equal(f.canvas.connecting.replaceTarget,'action:old');
    await f.canvas.completeConnection('action:new');
    assert.deepEqual(calls,[['action:if','no','action:new','action:old']]);
    assert.equal(f.canvas.connecting,null);
});

test('fit view scales the whole graph including captions without moving nodes', () => {
    const f = fixture();
    f.canvas.positions = { 'action:test': {x: 110, y: -30} };
    f.canvas.$refs.viewport.clientWidth = 800;
    f.canvas.$refs.viewport.clientHeight = 600;
    f.canvas.$refs.stage = {getBoundingClientRect: () => ({left: 0, top: 0})};
    const caption = {getBoundingClientRect: () => ({left: -48, top: 112, right: 1800, bottom: 500})};
    const card = {getBoundingClientRect: () => ({left: 0, top: 0, right: 1700, bottom: 400})};
    f.canvas.nodeElements = () => [{querySelector: (s) => s === '[data-workflow-node-card]' ? card : caption}];
    f.canvas.fitView();
    assert.ok(f.canvas.scale < 1);
    assert.ok(1800 * f.canvas.scale + f.canvas.translateX <= 752);
    assert.ok(-48 * f.canvas.scale + f.canvas.translateX >= 48 - 1e-9);
    assert.equal(f.canvas.positions['action:test'].x, 110);
    assert.match(f.canvas.stageStyle(), /scale\(/);
});

test('execution badges do not trigger repeated text mutations during canvas refresh', () => {
    const f = fixture();
    let writes = 0;
    let text = '';
    const badge = {hidden: true, get textContent() { return text; }, set textContent(value) { writes++; text = value; }};
    const card = {dataset: {}, querySelector: () => badge};
    f.canvas.nodeElements = () => [{dataset: {workflowNodeId: 'action:test'}, querySelector: () => card}];
    f.canvas.setExecutionState({results: [{id: 'test', status: 'completed', sequence: 1}]});
    f.canvas.applyExecutionState();
    f.canvas.applyExecutionState();
    assert.equal(writes, 1);
    assert.equal(card.dataset.executionStatus, 'completed');
    assert.equal(badge.hidden, false);
});
