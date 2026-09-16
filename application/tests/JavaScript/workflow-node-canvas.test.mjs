import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

test('canvas keeps its dotted grid and uses vertical input ports', () => {
    const css = readFileSync(new URL('../../resources/css/filament-workflows.css',import.meta.url),'utf8');
    assert.equal((css.match(/radial-gradient\(circle/g) ?? []).length,7);
    assert.match(css,/\.workflow-node-port--input::before\s*\{[^}]*width:\s*3px;[^}]*height:\s*14px;/s);
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
    f.canvas.startCanvasPan(f.event());
    assert.equal(f.canvas.panning,false);
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
    const css = readFileSync(new URL('../../resources/css/filament-workflows.css',import.meta.url),'utf8');
    const rule = css.match(/\.workflow-sortable-item\s*\{([^}]+)\}/)[1];
    assert.doesNotMatch(rule,/transition:[^;]*transform/);
});

test('clicking a connected output reassigns that edge, including after pointer release', async () => {
    const f = fixture();
    f.canvas.$refs.edgeControls = {querySelectorAll: () => [{dataset: {workflowEdgeSource:'action:if', workflowEdgePort:'no', workflowEdgeTarget:'action:old'}}]};
    let args;
    f.canvas.$wire = {connectWorkflowNodes: async (...values) => { args = values; }};
    f.canvas.startConnection('action:if','no',f.event());
    f.canvas.stopCanvasInteraction(f.event());
    assert.equal(f.canvas.connecting.replaceTarget, 'action:old');
    await f.canvas.completeConnection('action:new');
    assert.deepEqual(args,['action:if','no','action:new','action:old']);
    assert.equal(f.canvas.connecting,null);
});

function fixture() {
    class Element {}
    let hitElement = null, hitStack = null;
    const window = { addEventListener() {}, requestAnimationFrame(callback) { callback(); }, setTimeout(callback) { callback(); return 1; }, clearTimeout() {} };
    let mutationCallback, observedOptions, resizeCallback;
    const resizedElements = new Set();
    runInNewContext(readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8'), {
        window, document: { addEventListener() {}, elementsFromPoint() { return hitStack || [hitElement]; }, elementFromPoint() { return hitElement; }, createElementNS() {
            return {attributes:{}, dataset:{}, listeners:{}, classList:{add(){}},
                setAttribute(key,value) {this.attributes[key]=value;}, appendChild(){},
                addEventListener(key,fn) {this.listeners[key]=fn;}};
        } }, Element,
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
    canvas.$nextTick = callback => callback();
    let captures = 0;
    let saves = 0;
    canvas.$refs = { viewport: { setPointerCapture() { captures++; }, releasePointerCapture() {}, classList: node.classList } };
    canvas.scheduleGraphRefresh = () => {};
    canvas.scheduleGraphRefreshAfterPaint = () => {};
    canvas.saveNodeLayout = () => { saves++; };
    const event = (x = 10, y = 20) => ({ target, pointerId: 1, button: 0, clientX: x, clientY: y, prevented: false, preventDefault() { this.prevented = true; } });

    return { canvas, node, target, event, mutate: (records) => mutationCallback(records), resize: () => resizeCallback(), resizedElements, observedOptions: () => observedOptions, setHit: (el) => {hitElement = el;}, setHitStack: els => {hitStack = els;}, captures: () => captures, saves: () => saves };
}

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

test('Shift rectangle selects several nodes and group drag preserves relative spacing at zoom', () => {
    const f = fixture();
    const second = {...f.node, dataset:{workflowNodeId:'action:second'},style:{},getBoundingClientRect:()=>({left:100,top:40,right:140,bottom:80})};
    f.node.getBoundingClientRect=()=>({left:20,top:40,right:60,bottom:80});
    f.canvas.nodeElements=()=>[f.node,second];
    f.canvas.$refs.viewport.getBoundingClientRect=()=>({left:0,top:0});
    f.canvas.startCanvasPan({...f.event(0,0),shiftKey:true});
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
    assert.equal(f.canvas.positions.unrelated.x,0);
    assert.equal(f.canvas.positions.unrelated.y,0);
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
