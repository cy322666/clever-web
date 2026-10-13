import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

function localDraft(actions, edges) {
    const {canvas} = fixture();
    canvas.$wire = {workflowActions:actions, definition:{actions,connections:edges}, $set(path, value, live) {
        assert.equal(live,false,'Local edits must never issue a request');
        const keys = path.split('.');
        let target = this;
        while (keys.length > 1) target = target[keys.shift()];
        target[keys[0]] = value;
    }};
    return canvas;
}

test('local toggle updates nested draft configuration and can be reversed without a server call', () => {
    const actions = [{id:'if',type:'control-condition',config:{true_actions:[{id:'a',type:'http_request',config:{url:'https://example.test'}}]}}];
    const canvas = localDraft(actions, []);
    for (const disabled of [true,false,true]) {
        canvas.toggleCanvasAction('a');
        assert.equal(canvas.canvasAction('a').disabled,disabled);
        assert.equal(canvas.$wire.definition.actions[0].config.true_actions[0].disabled,disabled);
        assert.equal(canvas.canvasAction('a').config.url,'https://example.test');
        assert.equal(actions[0].config.true_actions[0].disabled,undefined,'Do not mutate an in-flight payload or baseline');
    }
});

test('local removal bridges ordinary nodes, preserves branch children and keeps loop return semantics', () => {
    const edge = (s,p,t) => ({sourceId:s,sourcePort:p,targetId:t});
    const canvas = localDraft([
        {id:'loop',type:'workflow_loop'},
        {id:'if',type:'control-condition',config:{true_actions:[{id:'a',type:'workflow_delay'}],false_actions:[{id:'b',type:'workflow_delay'}]}},
        {id:'end',type:'workflow_delay'},
    ],[edge('trigger','output','action:loop'),edge('action:loop','each','action:if'),edge('action:if','yes','action:a'),edge('action:if','no','action:b'),edge('action:a','output','action:end'),edge('action:b','output','action:loop'),edge('action:end','output','action:loop')]);
    canvas.removeCanvasAction('end');
    let edges=canvas.canvasEdges([], ['trigger']);
    assert.equal(edges.find(e=>e.sourceId==='action:a').targetId,'action:loop');
    assert.equal(edges.find(e=>e.sourceId==='action:a').loopReturn,true);
    assert.equal(edges.find(e=>e.sourceId==='action:loop'&&e.sourcePort==='each').loopReturn,false);
    canvas.removeCanvasAction('if');
    assert.deepEqual(Array.from(canvas.canvasActions().map(a=>a.id)),['loop','a','b']);
    assert.equal(canvas.$wire.definition.connections.some(e=>e.sourceId==='action:loop'&&e.sourcePort==='each'),false,'Never bridge both condition branches');
    edges=canvas.canvasEdges([], ['trigger']);
    assert.equal(edges.find(e=>e.sourceId==='action:a').loopReturn,false,'Detached nodes are not loop returns');
    assert.equal(edges.find(e=>e.sourceId==='action:loop'&&e.sourcePort==='each').targetId,null);
});

test('local edge projection recognizes done feedback, multiple starts and empty output ports', () => {
    const canvas=localDraft([{id:'before',type:'workflow_delay'},{id:'loop',type:'workflow_loop'}],[
        {sourceId:'trigger:two',sourcePort:'output',targetId:'action:before'},
        {sourceId:'action:before',sourcePort:'output',targetId:'action:loop'},
        {sourceId:'action:loop',sourcePort:'done',targetId:'action:before'},
    ]);
    const edges=canvas.canvasEdges([],['trigger','trigger:two']);
    assert.equal(edges.find(e=>e.sourcePort==='done').loopReturn,true);
    assert.equal(edges.find(e=>e.sourcePort==='each').targetId,null);
    assert.equal(edges.find(e=>e.sourceId==='trigger').targetId,null);
    assert.equal(edges.find(e=>e.sourceId==='trigger:two').targetId,'action:before');
});

test('condition edges are neutral until evaluated and only the selected route is green', () => {
    const {canvas} = fixture();
    const edge = port => ({sourceId:'action:condition',sourcePort:port,targetId:'action:next'});
    for (const port of ['yes','no']) assert.equal(canvas.edgeCompleted(edge(port)),false);
    for (const passed of [true,false]) {
        canvas.executionState = {results:[{id:'condition',status:'completed',output:{passed}}]};
        assert.equal(canvas.edgeCompleted(edge('yes')),passed);
        assert.equal(canvas.edgeCompleted(edge('no')),!passed);
        // A branch decision is available before its target has completed.
        canvas.executionState.results.push({id:'next',status:'running'});
        assert.equal(canvas.edgeCompleted(edge(passed ? 'yes' : 'no')),true);
    }
    for (const status of ['running','error','failed']) {
        canvas.executionState = {results:[{id:'condition',status,output:{passed:true}},{id:'next',status:'completed'}]};
        for (const port of ['yes','no']) assert.equal(canvas.edgeCompleted(edge(port)),false);
    }
    canvas.executionState = {results:[{id:'condition',status:'completed',output:{}}]};
    for (const port of ['yes','no']) assert.equal(canvas.edgeCompleted(edge(port)),false);
    canvas.executionState = {results:[{id:'condition',status:'skipped'}]};
    assert.equal(canvas.edgeCompleted(edge('yes')),true);
    assert.equal(canvas.edgeCompleted(edge('no')),false);
    assert.equal(canvas.edgeCompleted(edge('no'),'true'),true);
    assert.equal(canvas.edgeCompleted(edge('yes'),'false'),false);
    const css = readFileSync(new URL('../../resources/css/filament-workflows.css',import.meta.url),'utf8');
    const source = readFileSync(new URL('../../resources/js/app.js',import.meta.url),'utf8');
    assert.doesNotMatch(css,/workflow-node-edge--(?:yes|no)/);
    assert.match(source,/const marker = completed \? 'completed' : 'default'/);
    assert.match(source,/const completed = !preview && this\.edgeCompleted/);
});

test('canvas keeps its dotted grid and uses round input ports like the prototype', () => {
    const css = readFileSync(new URL('../../resources/css/filament-workflows.css',import.meta.url),'utf8');
    const grid = readFileSync(new URL('../../resources/views/filament/workflow-builder/workflow-canvas-grid.blade.php',import.meta.url),'utf8');
    assert.doesNotMatch(css,/radial-gradient\(circle/,'Do not reintroduce fractionally rounded CSS background tiles');
    assert.match(css,/\.workflow-canvas-grid\s*\{[^}]*position:\s*absolute;[^}]*pointer-events:\s*none;/s);
    assert.match(css,/\.dark \.workflow-canvas-grid\s*\{[^}]*fill:\s*#555863;/s);
    assert.match(grid,/aria-hidden="true" focusable="false" x-id="\['workflow-grid'\]" wire:ignore/);
    assert.match(grid,/patternUnits="userSpaceOnUse"/);
    assert.match(grid,/x-bind:width="gridSpacing" x-bind:height="gridSpacing"/);
    assert.match(grid,/x-bind:pattern-transform\.camel="gridTransform\(\)"/);
    assert.match(grid,/x-bind:cx="gridSpacing \/ 2" x-bind:cy="gridSpacing \/ 2"/);
    assert.match(grid,/x-bind:fill="`url\(#\$\{\$id\('workflow-grid'\)\}\)`"/);
    assert.match(grid,/x-bind:opacity="gridOpacity\(\)"/);
    assert.match(css,/\.workflow-node-port--input::before\s*\{[^}]*width:\s*9px;[^}]*height:\s*9px;[^}]*border-radius:\s*50%;/s);
});

test('interaction effects preserve node geometry and respect reduced motion', () => {
    const css = readFileSync(new URL('../../resources/css/workflow-inspector.css',import.meta.url),'utf8');
    assert.match(css,/\[data-node-running="true"\]::after\s*\{[^}]*pointer-events:\s*none;[^}]*animation:\s*workflow-node-pulse/s);
    assert.match(css,/@keyframes workflow-node-pulse/);
    assert.match(css,/@media \(prefers-reduced-motion: reduce\)[\s\S]*\[data-workflow-node-card\]::after[^}]*animation: none !important/);
    assert.doesNotMatch(css,/\[data-workflow-node-card\]:hover\s*\{[^}]*(?:transform|translate|scale):/);
});

function gridGeometry(canvas) {
    const [, x, y, scale, dx, dy] = canvas.gridTransform().match(/^translate\((\S+) (\S+)\) scale\((\S+)\) translate\((\S+) (\S+)\)$/).map(Number);
    // SVG transforms compose right to left. The circle sits in the tile centre.
    return {pitch:canvas.gridSpacing*scale, x:x+(dx+canvas.gridSpacing/2)*scale, y:y+(dy+canvas.gridSpacing/2)*scale};
}

test('backdrop dots stay on the same world coordinates through fractional zoom, pan and native scroll', () => {
    const {canvas} = fixture();
    for (const view of ['vendor/filament-workflows/components/workflow-builder', 'filament/workflow-builder/workflow-execution-canvas']) {
        assert.match(readFileSync(new URL(`../../resources/views/${view}.blade.php`, import.meta.url), 'utf8'), /@include\('filament\.workflow-builder\.workflow-canvas-grid'\)/);
    }
    for (const scale of [0.01, 0.05, 0.249, 0.25, 0.499, 0.5, 0.599, 0.6, 0.601, 0.999, 1, 1.333, 2]) {
        canvas.scale = scale;
        canvas.translateX = -123;
        canvas.translateY = 456;
        canvas.$refs.stage = {offsetLeft:7,offsetTop:11};
        canvas.$refs.viewport = {scrollLeft:3,scrollTop:9};
        const verify = () => {
            const grid = gridGeometry(canvas);
            assert.equal(grid.pitch, 20 * scale, 'The world grid must never switch density at zoom thresholds');
            assert.equal(canvas.gridOpacity(),Math.min(1,(scale/0.35)**2));
            for (const [axis, offset, scroll] of [['x','offsetLeft','scrollLeft'], ['y','offsetTop','scrollTop']]) {
                const origin = canvas.$refs.stage[offset] + canvas[axis === 'x' ? 'translateX' : 'translateY'];
                for (const world of [-400, -20, 0, 20, 800]) {
                    const screen = origin + world * scale - canvas.$refs.viewport[scroll];
                    // The absolute SVG scrolls with the viewport content, just like the stage.
                    const cell = (screen - (grid[axis] - canvas.$refs.viewport[scroll])) / grid.pitch;
                    assert.ok(Math.abs(cell - Math.round(cell)) < 1e-9, `${axis}: world ${world} must remain on a dot at scale ${scale}`);
                }
            }
        };
        verify();
        canvas.translateX += 7.375;
        canvas.translateY -= 9.625;
        verify();
        canvas.$refs.viewport.scrollLeft += 31;
        canvas.$refs.viewport.scrollTop += 47;
        verify();
        assert.equal(canvas.snapCoordinate(14), 10);
        assert.equal(canvas.snapCoordinate(16), 20);
        assert.equal(canvas.snapCoordinate(-16), -20);
    }
});

test('wheel pan, pointer zoom and restored viewport keep the backdrop and stage in phase', () => {
    const {canvas, event} = fixture();
    canvas.$refs.stage = {offsetLeft:0,offsetTop:0};
    canvas.$refs.viewport = {clientWidth:800,clientHeight:600,scrollLeft:0,scrollTop:0,getBoundingClientRect:()=>({left:0,top:0})};
    const verify = () => {
        const grid = gridGeometry(canvas);
        const dot = axis => {
            const origin = axis === 'x' ? canvas.translateX : canvas.translateY;
            const n = Math.round((origin-grid[axis])/grid.pitch);
            return grid[axis]+n*grid.pitch;
        };
        assert.ok(Math.abs(dot('x')-canvas.translateX) < 1e-9);
        assert.ok(Math.abs(dot('y')-canvas.translateY) < 1e-9);
        assert.equal(grid.pitch/20,canvas.scale);
    };
    verify();
    canvas.onCanvasWheel({...event(),deltaX:13.25,deltaY:-29.5,deltaMode:0});
    verify();
    canvas.onCanvasWheel({...event(220,180),deltaX:0,deltaY:51,deltaMode:0,ctrlKey:true});
    verify();
    canvas.zoomCanvas(0.5);
    verify();
    canvas.saveViewport();
    const saved = canvas.gridTransform();
    canvas.translateX = canvas.translateY = 0;
    canvas.scale = 1;
    assert.equal(canvas.restoreViewport(),true);
    assert.equal(canvas.gridTransform(),saved);
    verify();
});

test('tiny zoom changes and pan across grid cells preserve the full, unwrapped transform', () => {
    const {canvas} = fixture();
    canvas.$refs.stage = {offsetLeft:7,offsetTop:11};
    for (let index = 0; index <= 200; index++) {
        canvas.scale = Math.exp((index-100)*0.001);
        canvas.translateX = -5000.125+index*0.375;
        canvas.translateY = 10000.875-index*0.625;
        const grid=gridGeometry(canvas);
        assert.equal(grid.x,canvas.translateX+7,'Pan must not wrap or round at tile boundaries');
        assert.equal(grid.y,canvas.translateY+11);
        assert.equal(grid.pitch,20*canvas.scale,'No pixel snapping or zoom thresholds');
    }
});

test('node overflow menu focuses enabled actions, cycles with arrows and restores focus on close', () => {
    const source = readFileSync(new URL('../../resources/js/app.js',import.meta.url),'utf8');
    const window = {dispatchEvent() {}};
    runInNewContext(source.slice(source.indexOf('window.workflowNodeMenu ='),source.indexOf('window.workflowNodeCanvas =')), {
        window, CustomEvent: class { constructor(type,options) { this.type=type;this.detail=options.detail; } },
    });
    const menu = window.workflowNodeMenu();
    let focused;
    const items = [false,true,false].map(disabled => ({disabled,focus(){focused=this;}}));
    const more = {focus(){focused=this;}};
    menu.$el = {};
    menu.$refs = {menu:{querySelectorAll:()=>items},more};
    menu.$nextTick = fn=>fn();
    menu.toggleMenu();
    assert.equal(menu.menuOpen,true);
    assert.equal(focused,items[0]);
    menu.moveMenuFocus({target:items[0]},1);
    assert.equal(focused,items[2]);
    menu.moveMenuFocus({target:items[2]},1);
    assert.equal(focused,items[0]);
    menu.moveMenuFocus({target:items[0]},-1);
    assert.equal(focused,items[2]);
    menu.toggleMenu();
    assert.equal(menu.menuOpen,false);
    assert.equal(focused,more);
});

test('node drags snap absolute card positions, preserve group spacing and never bypass with Alt', () => {
    for (const scale of [0.25, 1, 2]) for (const altKey of [false, true]) {
        const f = fixture();
        f.canvas.scale = scale;
        f.canvas.translateX = -573;
        f.canvas.translateY = 231;
        f.canvas.positions = {'action:test':{x:13,y:-7},other:{x:97,y:25}};
        f.canvas.selectedNodeIds = ['action:test','other'];
        f.canvas.cardPositions = () => ({'action:test':{x:47,y:83},other:{x:131,y:115}});
        f.canvas.startNodeDrag({...f.event(100,100),altKey}, f.node);
        f.canvas.moveCanvas({...f.event(100+23.4*scale,100-16.3*scale),altKey});
        assert.equal(47+f.canvas.positions['action:test'].x-13,70);
        assert.equal(83+f.canvas.positions['action:test'].y+7,70);
        assert.equal(f.canvas.positions.other.x-f.canvas.positions['action:test'].x,84);
        assert.equal(f.canvas.positions.other.y-f.canvas.positions['action:test'].y,32);
    }
});

test('curve and loop bend handles snap independently without moving endpoints or other routes', () => {
    for (const scale of [0.25, 1, 2]) {
        const f = fixture();
        f.canvas.scale = scale;
        const edge = {sourceId:'a',sourcePort:'each',targetId:'b'};
        const key = f.canvas.edgeRouteKey(edge);
        f.canvas.edgeRoutes.untouched = {kind:'curve',x:33,y:57};
        const cases = [
            ['curve', {kind:'curve',x:133,y:-117}, {x:160,y:-130}],
            ['exit', {kind:'loop',exitX:133,entryX:17,y:-117}, {exitX:160,entryX:17,y:-117}],
            ['entry', {kind:'loop',exitX:133,entryX:17,y:-117}, {exitX:133,entryX:40,y:-117}],
            ['lane', {kind:'loop',exitX:133,entryX:17,y:-117}, {exitX:133,entryX:17,y:-130}],
        ];
        for (const [part,route,expected] of cases) {
            f.canvas.startEdgeRouteDrag(edge,route,f.event(100,100),part);
            f.canvas.moveCanvas({...f.event(100+23*scale,100-16*scale),altKey:true});
            f.canvas.stopCanvasInteraction(f.event(100+23*scale,100-16*scale));
            for (const [name,value] of Object.entries(expected)) assert.equal(f.canvas.edgeRoutes[key][name],value);
        }
        assert.deepEqual(f.canvas.edgeRoutes.untouched,{kind:'curve',x:33,y:57});
        assert.deepEqual(Object.keys(f.canvas.positions),[]);
    }
});

test('connections are heavier without changing node borders or edge hit targets', () => {
    const css = readFileSync(new URL('../../resources/css/filament-workflows.css',import.meta.url),'utf8');
    assert.match(css, /\.workflow-node-edge\s*\{[^}]*stroke-width:\s*2\.5;[^}]*vector-effect:\s*non-scaling-stroke;/s);
    assert.match(css, /border:\s*1px solid #9da6b3 !important;/);
    assert.doesNotMatch(css, /border:\s*2px solid #9da6b3/);
    assert.match(css, /\.workflow-node-card--selected\s*\{\s*outline:\s*1px solid #339dc7/);
    assert.match(css, /\.workflow-node-edge\.is-selected\s*\{\s*stroke-width:\s*3\.5;/);
    assert.match(css, /\.workflow-node-edge-hit\s*\{[^}]*stroke-width:\s*16;[^}]*pointer-events:\s*stroke;/s);
});

test('new branches start from white output handles while existing edges keep hover controls', () => {
    const card = readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflows/action-card.blade.php', import.meta.url), 'utf8');
    const edges = readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflows/edge-controls.blade.php', import.meta.url), 'utf8');
    const css = readFileSync(new URL('../../resources/css/filament-workflows.css', import.meta.url), 'utf8');
    assert.match(card, /workflow-node-port--output-\{\{ \$port \}\}.*startConnection\('action:' \+ @js\(\$actionId\), @js\(\$port\), \$event\)/s);
    assert.match(css, /button\.workflow-node-port--output:hover::before[^{]*\{[^}]*background:\s*#2fa7d2/s);
    assert.match(edges, /x-for="edge in canvasEdges/);
    assert.match(edges, /class="workflow-node-edge-controls" wire:ignore wire:key="workflow-edge-controls-/);
    assert.match(edges, /x-show="edge.targetId"/);
    assert.match(edges, /heroicon-o-plus/);
    assert.match(edges, /heroicon-o-trash/);
    assert.doesNotMatch(edges, /data-workflow-edge-branch|Добавить ещё одну ветку/);
    assert.deepEqual(Array.from(fixture().canvas.canvasEdges([{branch:true}, {sourceId:'a',sourcePort:'yes',targetId:'b'}], [])), [{sourceId:'a',sourcePort:'yes',targetId:'b'}]);
});

test('new connection appears immediately but only the server can accept it into the draft', async () => {
    const {canvas}=fixture();let release;
    canvas.canvasActions=()=>[{id:'a',type:'workflow_delay'},{id:'b',type:'workflow_delay'}];
    canvas.$wire={definition:{connections:[]}, connectWorkflowNodes:async()=>new Promise(r=>release=r)};
    canvas.connecting={sourceId:'action:a',sourcePort:'output',replaceTarget:null};
    const pending=canvas.completeConnection('action:b');
    assert.equal(canvas.canvasEdges([],[]).some(edge=>edge.targetId==='action:b'),true);
    assert.deepEqual(canvas.$wire.definition.connections,[],'Preview must not become a saved connection before validation');
    // A server-side rejection leaves the actual draft untouched.
    release();await pending;
    assert.equal(canvas.pendingConnections.length,0);
    assert.equal(canvas.canvasEdges([],[]).some(edge=>edge.targetId==='action:b'),false);
    canvas.connecting={sourceId:'action:a',sourcePort:'output',replaceTarget:null};
    canvas.$wire.connectWorkflowNodes=async()=>{canvas.$wire.definition.connections=[{sourceId:'action:a',sourcePort:'output',targetId:'action:b'}];};
    await canvas.completeConnection('action:b');
    assert.equal(canvas.canvasEdges([],[]).some(edge=>edge.targetId==='action:b'),true);
});

test('failed reconnect restores the original edge and shows a retry message', async () => {
    const {canvas}=fixture();
    canvas.canvasActions=()=>[{id:'a',type:'workflow_delay'},{id:'b',type:'workflow_delay'},{id:'c',type:'workflow_delay'}];
    const original={sourceId:'action:a',sourcePort:'output',targetId:'action:b'};
    canvas.$wire={definition:{connections:[original]},connectWorkflowNodes:async()=>{throw new Error('offline');}};
    canvas.connecting={sourceId:'action:a',sourcePort:'output',replaceTarget:'action:b'};
    await canvas.completeConnection('action:c');
    assert.equal(canvas.pendingConnections.length,0);assert.match(canvas.connectionError,/Повторите/);
    assert.equal(canvas.canvasEdges([],[]).some(edge=>edge.targetId==='action:b'),true);
    assert.equal(canvas.canvasEdges([],[]).some(edge=>edge.targetId==='action:c'),false);
});

test('connection preview snaps to the input and clears target highlighting on cancellation', () => {
    const {canvas}=fixture();canvas.connecting={sourceId:'action:a',sourcePort:'output'};
    canvas.connectionTargetAt=()=> 'action:b';
    canvas.nodeElements=()=>[{dataset:{workflowNodeId:'action:b'},querySelector:()=>({getBoundingClientRect:()=>({left:100,top:200,width:14,height:20})})}];
    canvas.moveCanvas({clientX:116,clientY:222});
    assert.equal(canvas.connectionTargetId,'action:b');
    assert.equal(canvas.connectionCursor.x,107);assert.equal(canvas.connectionCursor.y,210);
    canvas.cancelConnection();assert.equal(canvas.connectionTargetId,null);
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

test('edge controls are centred on the path at every zoom without repeated style mutations', () => {
    const source = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
    const code = source.slice(source.indexOf('        const positionControl ='), source.indexOf('        const sourceSelector ='));
    for (const scale of [0.25, 1, 2]) {
        const positionedControls = new Set();
        const position = runInNewContext(code + '\npositionControl;', {positionedControls, scale});
        let writes = 0;
        const style = () => new Proxy({}, {set(target,key,value) {writes++; target[key]=value; return true;}});
        const remove = {hasAttribute:()=>true,style:style()};
        const plus = {style:style(),nextElementSibling:remove,getBoundingClientRect:()=>({width:16*scale})};
        position(plus,100,50);
        assert.equal(plus.style.left,'90px');
        assert.equal(remove.style.left,'110px');
        assert.equal(remove.style.top,'50px');
        const before = writes;
        position(plus,100,50);
        assert.equal(writes,before);
        const empty = {style:style()};
        position(empty,100,50);
        assert.equal(empty.style.left,'100px');
    }
    assert.match(source,/positionControl\(button,route\.x,route\.y\)/);
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

test('loop empty outputs keep done above each without crossing their plus buttons', () => {
    const source = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
    const code = source.slice(source.indexOf('controls.filter((button) => !button.dataset.workflowEdgeTarget)'), source.indexOf('        controls.forEach((button) => {'));
    for (const scale of [0.25, 1, 2]) {
        const controls = ['each', 'done'].map(port => ({dataset:{workflowEdgeSource:'loop',workflowEdgePort:port},getBoundingClientRect:()=>({width:22*scale})}));
        const card = {querySelector:port=>({getBoundingClientRect:()=>({left:100*scale,top:(port==='done'?38:62)*scale,width:0,height:0})})};
        const positions = {}, paths = [];
        runInNewContext(code, {controls,nodeById:new Map([['loop',{querySelector:()=>card}]]),sourceSelector:port=>port,stageRect:{left:0,top:0},scale,paths,positionControl:(button,x,y)=>{positions[button.dataset.workflowEdgePort]={x,y};},maxX:0,maxY:0});
        assert.ok(positions.done.y < 38);
        assert.ok(positions.each.y > 62);
        assert.equal(positions.done.x, positions.each.x);
        assert.equal(paths.length, 2);
    }
});

test('new detached node is centered in visible viewport at any zoom and pan without moving other nodes', () => {
    for (const scale of [0.2, 1, 2]) {
        const f = fixture();
        f.canvas.scale = scale;
        f.canvas.$refs.viewport.getBoundingClientRect = () => ({left:320,top:100,width:1000,height:700});
        f.canvas.$refs.stage = {getBoundingClientRect:()=>({left:-400,top:-250})};
        f.node.querySelector = () => ({getBoundingClientRect:()=>({width:96*scale,height:96*scale})});
        let desired;
        f.canvas.restoreCardPositions = value => {desired = value;};
        f.canvas.applyNodeSelection = () => {};
        f.canvas.placeCreatedNode({nodeId:'action:test'});
        assert.deepEqual(Object.keys(desired), ['action:test']);
        assert.ok(Math.abs(-400 + (desired['action:test'].x+48)*scale - 820) <= 5*scale);
        assert.ok(Math.abs(-250 + (desired['action:test'].y+48)*scale - 450) <= 5*scale);
        assert.equal(desired['action:test'].x % 10, 0);
        assert.equal(desired['action:test'].y % 10, 0);
        assert.deepEqual(Array.from(f.canvas.selectedNodeIds), ['action:test']);
    }
    const markup = readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflow-builder.blade.php',import.meta.url),'utf8');
    assert.match(markup, /workflow-node-created.window="placeCreatedNode\(\$event.detail\)"/);
});

test('an imported layout survives unavailable local storage', () => {
    const f = fixture();
    f.canvas.initialLayout = {'action:test':{x:120,y:80}};
    f.canvas.restoreNodeLayout();
    assert.equal(f.canvas.positions['action:test'].x,120);
    assert.equal(f.canvas.positions['action:test'].y,80);
});

test('copied groups are centered at the current pan and zoom while preserving spacing and original positions', () => {
    for (const scale of [0.2, 1, 2]) {
        const f = fixture();
        f.canvas.scale = scale;
        f.canvas.$refs.viewport.getBoundingClientRect = () => ({left:200,top:100,width:1000,height:700});
        f.canvas.$refs.stage = {getBoundingClientRect:()=>({left:-1300,top:420})};
        f.canvas.nodeElements = () => ['copy-a','copy-b','original'].map(id => ({dataset:{workflowNodeId:id},
            querySelector:()=>({getBoundingClientRect:()=>({width:96*scale,height:96*scale})})}));
        let desired;
        f.canvas.restoreCardPositions = value => {desired = value;};
        f.canvas.applyNodeSelection = () => {};
        const positions = {a:{x:10000,y:-6000}, b:{x:10200,y:-6300}};
        const map = {a:'copy-a',b:'copy-b'};
        f.canvas.centerCopiedNodes(map, positions);
        assert.deepEqual(Object.keys(desired), ['copy-a','copy-b']);
        assert.equal(desired['copy-b'].x-desired['copy-a'].x,200);
        assert.equal(desired['copy-b'].y-desired['copy-a'].y,-300);
        assert.ok(Math.abs(-1300+(desired['copy-a'].x+148)*scale-700) <= 5*scale);
        assert.ok(Math.abs(420+(desired['copy-b'].y+198)*scale-450) <= 5*scale);
        assert.equal(desired['copy-a'].x % 10, 0);
        assert.equal(Math.abs(desired['copy-b'].y % 10), 0);
        assert.equal(positions.a.x,10000);
        assert.deepEqual(Array.from(f.canvas.selectedNodeIds), ['copy-a','copy-b']);
        f.canvas.cardPositions = () => positions;
        f.canvas.placeCopiedNodes({map});
        assert.ok(Math.abs(-1300+(desired['copy-a'].x+148)*scale-700) <= 5*scale);
    }
    const markup = readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflow-builder.blade.php',import.meta.url),'utf8');
    assert.match(markup, /workflow-nodes-copied.window="placeCopiedNodes\(\$event.detail\)"/);
});

test('keyboard paste uses the same centered group placement and still leaves text fields alone', async () => {
    const f = fixture();
    f.canvas.$el = {isConnected:true,getClientRects:()=>[{}]};
    f.canvas.$nextTick = async () => {};
    f.canvas.applyNodePositions = () => {};
    const positions = {source:{x:9000,y:-5000}}, map = {source:'copy'};
    f.canvas.clipboardPositions = positions;
    let centered = 0;
    f.canvas.centerCopiedNodes = (received,points) => { assert.equal(received,map); assert.equal(points,positions); centered++; };
    f.canvas.$wire = {pasteWorkflowNodes:async()=>map};
    await f.canvas.clipboardShortcut({ctrlKey:true,key:'v',target:{closest:()=>true},preventDefault(){}});
    assert.equal(centered,0);
    for (let n=0;n<2;n++) await f.canvas.clipboardShortcut({metaKey:true,key:'v',target:{closest:()=>false},preventDefault(){}});
    assert.equal(centered,2);
    assert.equal(f.canvas.pasting,false);
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
    f.canvas.startCanvasInteraction(f.event());
    assert.equal(f.canvas.selectionOrigin,null);
});

test('captured plus taps open once and movement does not animate behind the edges', () => {
    const f = fixture();
    let calls=0;
    f.canvas.$wire = {$set: (key, value, live) => { assert.equal(live, false); if (key === 'insertConnection') calls++; }};
    f.canvas.startEdgeControlDrag('trigger','output',null,f.event());
    assert.equal(f.captures(),1);
    f.canvas.stopCanvasInteraction(f.event());
    f.canvas.openEdgePalette('trigger','output',null);
    assert.equal(calls,1);
    assert.equal(f.events().length, 1);
    assert.equal(f.events()[0].type, 'workflow-node-library-open');
    assert.equal(f.events()[0].detail.mode, 'all');
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
    const CustomEvent = class { constructor(type, init = {}) { this.type = type; this.detail = init.detail; } };
    const window = { addEventListener() {}, dispatchEvent(event) { events.push({type:event.type,detail:event.detail}); }, requestAnimationFrame(callback) { callback(); }, setTimeout(callback) { callback(); return 1; }, clearTimeout() {} };
    window.sessionStorage = {getItem: key => viewportStorage.get(key) ?? null, setItem: (key, value) => viewportStorage.set(key, value)};
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
    canvas.$nextTick = callback => callback();
    let captures = 0;
    let saves = 0;
    canvas.$refs = { viewport: { setPointerCapture() { captures++; }, releasePointerCapture() {}, classList: node.classList } };
    canvas.scheduleGraphRefresh = () => {};
    canvas.scheduleGraphRefreshAfterPaint = () => {};
    const persistLayout = canvas.saveNodeLayout.bind(canvas);
    canvas.saveNodeLayout = () => { saves++; };
    const event = (x = 10, y = 20) => ({ target, pointerId: 1, button: 0, clientX: x, clientY: y, prevented: false, preventDefault() { this.prevented = true; } });

    return { canvas, node, target, event, viewportStorage, window, persistLayout, mutate: (records) => mutationCallback(records), resize: () => resizeCallback(), resizedElements, observedOptions: () => observedOptions, setHit: (el) => {hitElement = el;}, setHitStack: els => {hitStack = els;}, captures: () => captures, saves: () => saves, events: () => events };
}

test('layout ignores iteration returns but rejects ordinary cycles and puts done above each', () => {
    const {canvas} = fixture();
    const ids = ['trigger', 'loop', 'body', 'done'];
    const edges = [{source:'trigger',target:'loop',port:'output'}, {source:'loop',target:'body',port:'each'},
        {source:'loop',target:'done',port:'done'}, {source:'body',target:'loop',port:'output',loopReturn:true}];
    const layout = canvas.graphLayout(ids,edges);
    assert.ok(layout);
    assert.ok(layout.body.x > layout.loop.x);
    assert.ok(layout.done.y < layout.body.y);
    edges[3].loopReturn = false;
    assert.equal(canvas.graphLayout(ids,edges),null);
});

test('return path is rounded below the body and ends at the loop input', () => {
    const {canvas} = fixture();
    const route = canvas.connectionRoute({x:600,y:160},{x:100,y:160},true,{kind:'loop',exitX:624,entryX:76,y:360});
    assert.match(route.d,/^M 600 160 L/);
    assert.match(route.d,/Q/);
    assert.doesNotMatch(route.d,/C/);
    assert.match(route.d,/L 100 160$/);
    assert.equal(route.y,360);
    assert.equal(route.right,624);
});

test('drawn return controls depend only on endpoints, not intermediate cards', () => {
    const f = fixture();
    const makeNode = (id,left,top) => {
        const card = {querySelector:()=>null,getBoundingClientRect:()=>({left,top,right:left+96,width:96,height:96})};
        return {dataset:{workflowNodeId:id},querySelector:()=>card};
    };
    f.canvas.nodeElements = () => [makeNode('loop',100,100),makeNode('branch',300,300),makeNode('body',600,100)];
    f.canvas.$refs.stage = {clientWidth:800,clientHeight:500,querySelector:()=>null,getBoundingClientRect:()=>({left:0,top:0})};
    const control = {dataset:{workflowEdgeSource:'body',workflowEdgePort:'output',workflowEdgeTarget:'loop',workflowLoopReturn:'true'},style:{}};
    f.canvas.$refs.edgeControls = {querySelectorAll:()=>[control]};
    let elements;
    f.canvas.$refs.edgeLayer = {style:{},setAttribute(){},replaceChildren(...items){elements=items;}};
    f.canvas.drawConnections();
    assert.equal(control.style.top,'292px');
    assert.ok(elements.some(e => e.attributes.d?.includes('292')));
});

function routingFixture(boxes, edges, scale = 1) {
    const f = fixture(), origin = {x:57,y:89};
    const screenRect = r => ({left:origin.x+r.left*scale,top:origin.y+r.top*scale,
        right:origin.x+r.right*scale,bottom:origin.y+r.bottom*scale,width:(r.right-r.left)*scale,height:(r.bottom-r.top)*scale});
    const nodes = boxes.map(box => {
        const rect = () => ({left:box.x,top:box.y,right:box.x+96,bottom:box.y+96});
        const caption = {getBoundingClientRect:()=>screenRect({left:box.x-(box.captionLeft??42),top:box.y+110,right:box.x+(box.captionRight??138),bottom:box.y+154})};
        const card = {getBoundingClientRect:()=>screenRect(rect()),querySelector:selector => {
            if (selector === '.workflow-node-card__caption') return caption;
            const input = selector === '.workflow-node-port--input';
            const ratio = /-(yes|done)$/.test(selector) ? .3 : /-(no|each)$/.test(selector) ? .7 : .5;
            const x = box.x+(input?(box.portInset??0):96-(box.portInset??0));
            return {getBoundingClientRect:()=>screenRect({left:x,right:x,top:box.y+96*ratio,bottom:box.y+96*ratio})};
        }};
        return {dataset:{workflowNodeId:box.id},querySelector:()=>card};
    });
    f.canvas.nodeElements = () => nodes;
    f.canvas.scale = scale;
    f.canvas.$refs.stage = {clientWidth:1200,clientHeight:900,querySelector:()=>null,getBoundingClientRect:()=>({left:origin.x,top:origin.y})};
    const controls = edges.map(e => ({dataset:{workflowEdgeSource:e.source,workflowEdgeTarget:e.target,workflowEdgePort:e.port??'output',workflowLoopReturn:String(e.loopReturn??false)},style:{}}));
    f.canvas.$refs.edgeControls = {querySelectorAll:()=>controls};
    let rendered;
    f.canvas.$refs.edgeLayer = {style:{},setAttribute(){},replaceChildren(...items){rendered=items;}};
    const draw = () => {
        f.canvas.drawConnections();
        return rendered.filter(e => (e.attributes.class??'').split(' ').includes('workflow-node-edge')).map(e=>e.attributes.d);
    };
    return {...f,draw,controls,elements:()=>rendered};
}


test('ordinary backward links remain smooth and ignore other cards and captions', () => {
    const boxes = [{id:'loop',x:140,y:350},{id:'http',x:500,y:90},{id:'if',x:780,y:90},{id:'done',x:440,y:580}];
    const f = routingFixture(boxes,[{source:'if',target:'loop',port:'no'}]);
    const [path] = f.draw();
    assert.match(path,/C/);
    assert.doesNotMatch(path,/Q/);
    boxes[1].y = 900;
    boxes[3].x = 650;
    assert.equal(f.draw()[0],path);
});

test('loop side legs stay angular with distinct return lanes, ignoring other nodes', () => {
    const boxes = [{id:'loop',x:140,y:160},{id:'body',x:780,y:160},{id:'side',x:890,y:340},{id:'below',x:90,y:470}];
    const f = routingFixture(boxes,[{source:'body',target:'loop',port:'yes',loopReturn:true},{source:'body',target:'loop',port:'no',loopReturn:true}]);
    const paths = f.draw();
    paths.forEach(path=>{
        assert.match(path,/Q/);
        assert.doesNotMatch(path,/C/);
    });
    assert.ok(parseFloat(f.controls[1].style.top)-parseFloat(f.controls[0].style.top)>=24);
    boxes[2].y = 450;
    boxes[3].y = 2000;
    assert.deepEqual(f.draw(),paths);
});

test('forward edges pass behind an intervening node and never reroute when it moves', () => {
    const boxes = [{id:'source',x:100,y:150},{id:'middle',x:400,y:150},{id:'target',x:720,y:150}];
    const f = routingFixture(boxes,[{source:'source',target:'target'}]);
    const [blocked] = f.draw();
    assert.match(blocked,/C/);
    assert.doesNotMatch(blocked,/Q/);
    assert.match(blocked,/M 196 198 C [\d.]+ 198, [\d.]+ 198, 720 198/);
    boxes[1].y = 400;
    const [clear] = f.draw();
    assert.match(clear,/C/);
    assert.equal(clear,blocked);
    assert.deepEqual(Object.keys(f.canvas.positions),[]);
});

test('parallel start nodes join a long-caption node with flowing cubic connections', () => {
    const boxes = [{id:'schedule',x:100,y:100},{id:'manual',x:100,y:300},
        {id:'settings',x:350,y:100,captionLeft:80,captionRight:180}];
    const edges = [{source:'schedule',target:'settings'},{source:'manual',target:'settings'}];
    const expected = routingFixture(boxes,edges).draw();
    for (const scale of [.5,1,2]) {
        const paths = routingFixture(boxes,edges,scale).draw();
        assert.deepEqual(paths,expected);
        paths.forEach(path=>{
            assert.match(path,/C/);
            assert.doesNotMatch(path,/[LQ]/);
        });
    }
});

test('real port centres inset into card borders do not force ordinary connections into detours', () => {
    const boxes = [{id:'schedule',x:100,y:100},{id:'manual',x:100,y:300},{id:'settings',x:350,y:100}]
        .map(box=>({...box,captionLeft:48,captionRight:144,portInset:2}));
    const edges = [{source:'schedule',target:'settings'},{source:'manual',target:'settings'}];
    for (const scale of [.5,1,2]) {
        routingFixture(boxes,edges,scale).draw().forEach(path=>{
            assert.match(path,/C/);
            assert.doesNotMatch(path,/[LQ]/);
        });
    }
});

test('connection routing uses canvas coordinates at different zoom levels', () => {
    const boxes = [{id:'loop',x:100,y:300},{id:'body',x:700,y:100},{id:'obstacle',x:350,y:300}];
    const edges = [{source:'body',target:'loop',loopReturn:true}];
    const expected = routingFixture(boxes,edges).draw();
    for (const scale of [.5,2]) {
        const paths = routingFixture(boxes,edges,scale).draw();
        assert.deepEqual(paths,expected);
    }
});

test('return insertion keeps the loop and existing body in place', () => {
    const f = fixture();
    const positions = {loop:{x:48,y:100},body:{x:500,y:100}};
    f.canvas.pendingInsertion = {sourceId:'body',targetId:'loop',nodes:positions,edges:[],dropPosition:null,loopReturn:true};
    let result;
    f.canvas.restoreCardPositions = desired => {result=desired;};
    f.canvas.placeInsertedNode({sourceId:'body',targetId:'loop',sourcePort:'output',nodeId:'new'});
    assert.deepEqual(result.loop,positions.loop);
    assert.deepEqual(result.body,positions.body);
    assert.ok(result.new.y > result.body.y);
});

test('pinned loop routes can run behind any node and survive endpoint movement and zoom', () => {
    const boxes = [{id:'action:loop',x:100,y:100},{id:'action:body',x:700,y:100},{id:'action:middle',x:370,y:320}];
    const edges = [{source:'action:body',target:'action:loop',loopReturn:true}];
    const key = JSON.stringify(['action:body','output','action:loop']);
    const saved = {kind:'loop',exitX:850,entryX:55,y:360};
    let expected;
    for (const scale of [.25,1,2]) {
        const f = routingFixture(boxes,edges,scale);
        f.canvas.edgeRoutes[key] = {...saved};
        const [path] = f.draw();
        assert.equal(f.controls[0].style.top,'360px');
        assert.match(path,/L 65 360 Q 55 360/);
        assert.doesNotMatch(path,/C|NaN/);
        if (expected) assert.equal(path,expected);
        expected = path;
        boxes[2].x += 10;
        assert.equal(f.draw()[0],path);
        boxes[0].y += 20;
        f.draw();
        assert.equal(f.controls[0].style.top,'360px');
        assert.deepEqual(f.canvas.edgeRoutes[key],saved);
        boxes[0].y -= 20;
    }
});

test('dragging routes is zoom-correct, does not reconnect or move nodes, and persists immediately', () => {
    for (const scale of [.25,1,2]) {
        const f = fixture();
        const changes = {};
        f.canvas.scale = scale;
        f.canvas.$wire = {definition:{},$set:(name,value,defer)=>{changes[name]=structuredClone(value);assert.equal(defer,false);}};
        f.canvas.saveNodeLayout = f.persistLayout;
        f.canvas.positions = {'action:test':{x:14,y:23}};
        const edge = {sourceId:'action:source',sourcePort:'no',targetId:'action:test'};
        const key = f.canvas.edgeRouteKey(edge);
        const route = {kind:'loop',exitX:600,entryX:100,y:200};
        f.canvas.startEdgeRouteDrag(edge,route,f.event(100,100));
        f.canvas.moveCanvas(f.event(100+40*scale,100+80*scale));
        f.canvas.stopCanvasInteraction(f.event(100+40*scale,100+80*scale));
        assert.equal(changes['definition.canvas_edge_routes'][key].y,280);
        assert.equal(changes['definition.canvas_edge_routes'][key].exitX,600);
        assert.deepEqual(changes['definition.canvas_layout'],{'action:test':{x:14,y:23}});
        assert.equal(f.canvas.connecting,null);
        const restored = fixture();
        restored.canvas.initialEdgeRoutes = changes['definition.canvas_edge_routes'];
        restored.canvas.restoreNodeLayout();
        assert.equal(restored.canvas.edgeRoutes[key].y,280);
    }
});

test('loop side handles can be fixed independently and curve handles move freely', () => {
    const f = fixture(), edge = {sourceId:'action:source',sourcePort:'output',targetId:'action:test'};
    const key = f.canvas.edgeRouteKey(edge);
    const original = {kind:'loop',exitX:600,entryX:100,y:320};
    for (const [part,field] of [['exit','exitX'],['entry','entryX']]) {
        f.canvas.startEdgeRouteDrag(edge,original,f.event(100,100),part);
        f.canvas.moveCanvas(f.event(150,180));
        f.canvas.stopCanvasInteraction(f.event(150,180));
        assert.equal(f.canvas.edgeRoutes[key][field],original[field]+50);
        assert.equal(f.canvas.edgeRoutes[key].y,320);
    }
    f.canvas.startEdgeRouteDrag(edge,{kind:'curve',x:350,y:120},f.event(100,100));
    f.canvas.moveCanvas(f.event(150,180));
    f.canvas.stopCanvasInteraction(f.event(150,180));
    assert.equal(f.canvas.edgeRoutes[key].x,400);
    assert.equal(f.canvas.edgeRoutes[key].y,200);
    const curve = f.canvas.connectionRoute({x:100,y:50},{x:700,y:50},false,f.canvas.edgeRoutes[key]);
    assert.match(curve.d,/, 400 200 C/);
    assert.doesNotMatch(curve.d,/[LQ]/);
});

test('clicking an edge selects it, cancellation restores the old route, and reset is saved', () => {
    const f = routingFixture([{id:'action:source',x:100,y:100},{id:'action:test',x:700,y:100}],
        [{source:'action:source',target:'action:test'}]);
    f.draw();
    const hit = f.elements().find(el=>el.attributes.class==='workflow-node-edge-hit');
    hit.listeners.pointerdown({...f.event(200,148),stopPropagation(){}});
    f.canvas.stopCanvasInteraction(f.event(200,148));
    assert.equal(f.canvas.selectedEdge.targetId,'action:test');
    assert.equal(f.canvas.connecting,null);
    assert.equal(f.saves(),0);
    f.draw();
    assert.ok(f.elements().some(el=>el.attributes.class==='workflow-edge-route-handle'));
    const key = f.canvas.edgeRouteKey(f.canvas.selectedEdge);
    const previous = {kind:'curve',x:300,y:250};
    f.canvas.edgeRoutes[key] = previous;
    f.canvas.startEdgeRouteDrag(f.canvas.selectedEdge,previous,f.event(100,100));
    f.canvas.moveCanvas(f.event(200,300));
    f.canvas.stopCanvasInteraction({...f.event(200,300),type:'pointercancel'});
    assert.equal(f.canvas.edgeRoutes[key].y,250);
    assert.equal(f.saves(),0);
    f.canvas.resetSelectedEdgeRoute();
    assert.equal(f.canvas.edgeRoutes[key],undefined);
    assert.equal(f.saves(),1);
});

test('saved routes reject malformed coordinates and browser storage cannot override a saved route', () => {
    const f = fixture(), key = JSON.stringify(['trigger','output','action:test']);
    f.window.localStorage = {getItem:()=>JSON.stringify({version:1,edges:{[key]:{kind:'curve',x:1,y:2}}})};
    f.canvas.initialEdgeRoutes = {[key]:{kind:'curve',x:50,y:60},'bad':{kind:'curve',x:0,y:0},
        '["trigger","yes","action:bad"]':{kind:'curve',x:Infinity,y:12}};
    f.canvas.restoreNodeLayout();
    assert.deepEqual(Object.keys(f.canvas.edgeRoutes),[key]);
    assert.equal(f.canvas.edgeRoutes[key].x,50);
    const css = readFileSync(new URL('../../resources/css/filament-workflows.css',import.meta.url),'utf8');
    assert.match(css,/\.workflow-node-edge-layer\s*\{[^}]*z-index:\s*1;/s);
    assert.match(css,/\.workflow-node-edge-controls\s*\{[^}]*z-index:\s*1;/s);
    assert.match(css,/\.workflow-sortable-item\[data-workflow-node-id\]\s*\{[^}]*z-index:\s*2;/s);
});

test('selection mode draws a fresh rectangle without a modifier and does not pan', () => {
    const f = fixture();
    f.target.closest = () => null;
    f.node.getBoundingClientRect = () => ({left:20,top:30,right:70,bottom:80});
    f.canvas.$refs.viewport.getBoundingClientRect = () => ({left:0,top:0});
    f.canvas.selectionMode = true;
    f.canvas.selectedNodeIds = ['old-selection'];
    f.canvas.startCanvasInteraction(f.event(0,0));
    f.canvas.moveCanvas(f.event(100,100));
    assert.deepEqual([...f.canvas.selectedNodeIds], ['action:test']);
    assert.equal(f.canvas.routeDrag, null);
    assert.equal(f.canvas.translateX, 0);
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

test('selection-mode clicks never open configuration and captions can drag the whole group', () => {
    const f = fixture();
    const closest = f.target.closest;
    f.target.closest = selector => selector.includes('.workflow-node-card__caption') ? {} : closest(selector);
    f.canvas.selectionMode = true;
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
    assert.ok(f.observedOptions().attributeFilter.includes('data-workflow-loop-return'));
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
    f.canvas.$refs.stage = {querySelector: () => null, querySelectorAll: selector => {
        assert.ok(selector.includes('.workflow-node-card__caption'));
        return geometry;
    }};
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
    assert.equal(boxes.inserted.x+f.canvas.positions.inserted.x,190);
    assert.equal(boxes.target.x+f.canvas.positions.target.x,384);
    assert.equal(boxes.tail.x+f.canvas.positions.tail.x,576);
    assert.equal(f.canvas.positions.unrelated?.x ?? 0,0);
    assert.equal(f.canvas.positions.unrelated?.y ?? 0,0);
    assert.equal(f.saves(),1);
});

test('clicking plus still opens the palette, dragging plus connects to a detached card', async () => {
    const f = fixture();
    let added = 0, connected;
    f.canvas.$wire = {$set: (key, value, live) => { assert.equal(live, false); if (key === 'insertConnection') added++; }, connectWorkflowNodes: async (...args) => {connected=args;}};
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
    f.canvas.setExecutionState({results: [{id: 'test', status: 'completed', sequence: 99, display_count: 1}]});
    f.canvas.applyExecutionState();
    f.canvas.applyExecutionState();
    assert.equal(writes, 1);
    assert.equal(card.dataset.executionStatus, 'completed');
    assert.equal(badge.hidden, false);
    assert.equal(text, '1');
    f.canvas.setExecutionState({results: [{id: 'test', status: 'completed', sequence: 99, display_count: 3, count_kind: 'iterations'}]});
    assert.equal(text, '3');
    assert.equal(badge.title, 'Итераций цикла');
    f.canvas.setExecutionState({results: [{id: 'test', status: 'failed', display_count: 0}]});
    assert.equal(card.dataset.executionStatus, 'failed');
    assert.equal(text, '0');
    f.canvas.setExecutionState({results: []});
    assert.equal(badge.hidden, true);
});
