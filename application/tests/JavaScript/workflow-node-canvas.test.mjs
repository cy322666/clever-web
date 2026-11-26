import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';
import {WorkflowEditorDraft} from '../../resources/js/workflow-editor-draft.mjs';

function assertOrthogonal(path, maximumBends = 4) {
    assert.doesNotMatch(path, /[CQASTHV]/, 'Connections use sharp horizontal and vertical segments');
    const values=path.match(/-?\d+(?:\.\d+)?(?:e[+-]?\d+)?/gi).map(Number);
    const points=Array.from({length:values.length/2},(_,index)=>({x:values[index*2],y:values[index*2+1]}));
    for (let index=1;index<points.length;index++) {
        assert.ok(Math.abs(points[index].x-points[index-1].x)<0.000001 || Math.abs(points[index].y-points[index-1].y)<0.000001, 'No diagonal segments');
    }
    assert.ok(points.length-2<=maximumBends, 'Routing must not add extra bends');
    return points;
}

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

test('primary and additional starts toggle locally without changing config or connections', () => {
    const canvas = localDraft([], [{sourceId:'trigger:timer',sourcePort:'output',targetId:'action:work'}]);
    const primary = {type:'manual',name:'Старт',config:{keep:42}};
    const additional = {id:'trigger:timer',type:'schedule',config:{timezone:'Europe/Moscow'}};
    canvas.$wire.trigger = primary;
    canvas.$wire.definition.trigger = primary;
    canvas.$wire.definition.additional_triggers = [additional];
    const edges = JSON.stringify(canvas.$wire.definition.connections);
    canvas.toggleCanvasTrigger('trigger');
    assert.equal(canvas.$wire.trigger.disabled,true);
    assert.equal(canvas.$wire.definition.trigger.disabled,true);
    assert.equal(primary.disabled,undefined,'Do not mutate the previous snapshot');
    assert.equal(canvas.$wire.trigger.config.keep,42);
    canvas.toggleCanvasTrigger('trigger:timer');
    assert.equal(canvas.$wire.definition.additional_triggers[0].disabled,true);
    assert.equal(canvas.$wire.definition.additional_triggers[0].config.timezone,'Europe/Moscow');
    canvas.toggleCanvasTrigger('trigger');
    canvas.toggleCanvasTrigger('trigger:timer');
    assert.equal(canvas.$wire.trigger.disabled,false);
    assert.equal(canvas.$wire.definition.additional_triggers[0].disabled,false);
    assert.equal(JSON.stringify(canvas.$wire.definition.connections),edges);
    canvas.toggleCanvasTrigger('missing');
    assert.equal(canvas.$wire.definition.additional_triggers.length,1);
});

test('manual areas create at the visible center, edit locally and never mutate the graph', () => {
    const canvas = localDraft([{id:'node',type:'workflow_delay'}], []);
    canvas.scale = 0.5;
    canvas.positions = {'action:node':{x:8,y:16}};
    canvas.edgeRoutes = {untouched:true};
    canvas.$refs.viewport.getBoundingClientRect = () => ({left:20,top:40,width:800,height:600});
    canvas.$refs.stage = {getBoundingClientRect:() => ({left:-100,top:-80}), querySelector:() => null};
    canvas.addCanvasArea();
    const area = canvas.canvasAreas()[0];
    assert.equal(area.x + area.width / 2, 1040);
    assert.equal(area.y + area.height / 2, 840);
    canvas.renameCanvasArea(area.id, '  Контакт  ');
    assert.equal(canvas.canvasAreas()[0].title, 'Контакт');
    const reloaded = localDraft([], []);
    reloaded.$wire.definition = JSON.parse(JSON.stringify(canvas.$wire.definition));
    assert.deepEqual(JSON.parse(JSON.stringify(reloaded.canvasAreas())), JSON.parse(JSON.stringify(canvas.canvasAreas())));
    canvas.removeCanvasArea(area.id);
    assert.equal(canvas.canvasAreas().length, 0);
    assert.deepEqual(canvas.$wire.workflowActions, [{id:'node',type:'workflow_delay'}]);
    assert.deepEqual(canvas.positions, {'action:node':{x:8,y:16}});
    assert.deepEqual(canvas.edgeRoutes, {untouched:true});
});

test('area drag and resize follow world zoom, pointer cancellation restores geometry', () => {
    const canvas = localDraft([], []);
    const original = {id:'stage_1',title:'Контакт',x:0,y:0,width:640,height:360};
    canvas.setCanvasAreas([original]);
    canvas.scale = 0.5;
    const event = {button:0,pointerId:1,clientX:100,clientY:100,preventDefault(){}};
    canvas.startAreaDrag(original.id, event);
    canvas.moveCanvas({...event,clientX:120,clientY:140});
    assert.equal(canvas.canvasAreas()[0].x, 40);
    assert.equal(canvas.canvasAreas()[0].y, 80);
    canvas.stopCanvasInteraction({...event,type:'pointercancel'});
    assert.deepEqual(JSON.parse(JSON.stringify(canvas.canvasAreas()[0])), original);
    canvas.startAreaDrag(original.id, event, true);
    canvas.moveCanvas({...event,clientX:140,clientY:120});
    canvas.stopCanvasInteraction(event);
    assert.equal(canvas.canvasAreas()[0].width, 720);
    assert.equal(canvas.canvasAreas()[0].height, 400);
    canvas.startAreaDrag(original.id, event, true);
    canvas.moveCanvas({...event,clientX:-10000,clientY:-10000});
    canvas.stopCanvasInteraction(event);
    assert.equal(canvas.canvasAreas()[0].width, 240);
    assert.equal(canvas.canvasAreas()[0].height, 160);
});

test('areas support keyboard geometry and malformed data cannot reach inline styles', () => {
    const canvas = localDraft([], []);
    const area = {id:'stage_1',title:'Этап',x:0,y:0,width:640,height:360};
    canvas.setCanvasAreas([area,area,{...area,id:'bad',x:'0;display:none'},{...area,id:'tiny',width:1}]);
    assert.equal(canvas.canvasAreas().length, 1);
    canvas.areaKeyboard(area.id, {key:'ArrowRight',shiftKey:true,preventDefault(){},stopPropagation(){}});
    assert.equal(canvas.canvasAreas()[0].x, 80);
    canvas.areaKeyboard(area.id, {key:'ArrowDown',preventDefault(){},stopPropagation(){}}, true);
    assert.equal(canvas.canvasAreas()[0].height, 368);
    canvas.initialAreas = [area];
    canvas.removeCanvasArea(area.id);
    assert.deepEqual(JSON.parse(JSON.stringify(canvas.canvasAreas()[0])), area, 'History areas are read-only snapshots');
});

test('areas always render behind graph, use text inputs and have a separate create button', () => {
    const view = readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflow-builder.blade.php', import.meta.url), 'utf8');
    const area = readFileSync(new URL('../../resources/views/filament/workflow-builder/workflow-canvas-areas.blade.php', import.meta.url), 'utf8');
    assert.ok(view.includes('aria-label="Создать область этапа"'));
    assert.ok(view.indexOf("@include('filament.workflow-builder.workflow-canvas-areas')") < view.indexOf('x-ref="edgeLayer"'));
    assert.ok(area.includes('x-for="area in canvasAreas()"'));
    assert.ok(!area.includes('x-show=') && !area.includes('x-html='));
    assert.ok(area.includes('x-on:pointerdown.stop'));
});

test('canvas chrome has a centered debug start and compact top-right controls', () => {
    const view = readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflow-builder.blade.php', import.meta.url), 'utf8');
    const css = readFileSync(new URL('../../resources/css/filament-workflows.css', import.meta.url), 'utf8');
    const inspectorCss = readFileSync(new URL('../../resources/css/workflow-inspector.css', import.meta.url), 'utf8');
    assert.match(view, /class="workflow-canvas-run" x-cloak/);
    assert.match(view, /if \(playing\) playing = false; else if \(!\$wire\.debugOpen\) openDebug\(\); else if \(\$wire\.debugState\.status === 'ready'\) runDebug\(\); else startDebug\(true\)/);
    assert.match(view, /x-bind:disabled="busy && !playing"/);
    assert.doesNotMatch(view, /@include\('filament.workflow-builder.workflow-debug-toolbar/);
    assert.match(css, /\.workflow-canvas-execution-controls \{[^}]*left: 50%; bottom: 20px;/);
    assert.match(css, /\.workflow-canvas-controls \.workflow-canvas-fit \{[^}]*width: 34px; height: 34px;/);
    assert.match(inspectorCss, /\.dark \.workflow-inspector \.workflow-node-output \{ background: transparent; \}/);
});

test('stage areas match the selected solid panel with an unnumbered title and contextual controls', () => {
    const css = readFileSync(new URL('../../resources/css/filament-workflows.css', import.meta.url), 'utf8');
    const view = readFileSync(new URL('../../resources/views/filament/workflow-builder/workflow-canvas-areas.blade.php', import.meta.url), 'utf8');
    const styles = css.slice(css.indexOf('.workflow-canvas-areas {'), css.indexOf('.workflow-node-edge {'));
    assert.match(styles, /background: #f1f4f8/);
    assert.match(styles, /\.dark \.workflow-canvas-area \{[^}]*background: #2b2e35/);
    assert.match(styles, /\.workflow-canvas-area__header \{[^}]*border: 0;[^}]*background: transparent/);
    assert.match(styles, /\.workflow-canvas-area__header input \{[^}]*font-size: 16px/);
    assert.match(styles, /\.workflow-canvas-area__header button \{[^}]*opacity: 0/);
    assert.match(styles, /:focus-within[^}]*opacity: 1/);
    assert.ok(view.indexOf('<input') < view.indexOf('class="workflow-canvas-area__move"'));
    assert.ok(view.includes(':value="area.title"') && !view.includes('index + 1'));
});

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

test('local removal bridges ordinary nodes, preserves branch children and keeps loop return semantics', async () => {
    const edge = (s,p,t) => ({sourceId:s,sourcePort:p,targetId:t});
    const canvas = localDraft([
        {id:'loop',type:'workflow_loop'},
        {id:'if',type:'control-condition',config:{true_actions:[{id:'a',type:'workflow_delay'}],false_actions:[{id:'b',type:'workflow_delay'}]}},
        {id:'end',type:'workflow_delay'},
    ],[edge('trigger','output','action:loop'),edge('action:loop','each','action:if'),edge('action:if','yes','action:a'),edge('action:if','no','action:b'),edge('action:a','output','action:end'),edge('action:b','output','action:loop'),edge('action:end','output','action:loop')]);
    const draft = new WorkflowEditorDraft(canvas.$wire, {names:{},actions:{},triggers:{}});
    canvas.$wire.removeWorkflowAction = id => draft.removeWorkflowAction(id);
    canvas.clientWire = draft.wire;
    await canvas.removeCanvasAction('end');
    let edges=canvas.canvasEdges([], ['trigger']);
    assert.equal(edges.find(e=>e.sourceId==='action:a').targetId,'action:loop');
    assert.equal(edges.find(e=>e.sourceId==='action:a').loopReturn,true);
    assert.equal(edges.find(e=>e.sourceId==='action:loop'&&e.sourcePort==='each').loopReturn,false);
    await canvas.removeCanvasAction('if');
    assert.deepEqual(Array.from(canvas.canvasActions().map(a=>a.id)),['loop','a','b']);
    assert.equal(draft.wire.definition.connections.some(e=>e.sourceId==='action:loop'&&e.sourcePort==='each'),false,'Never bridge both condition branches');
    edges=canvas.canvasEdges([], ['trigger']);
    assert.equal(edges.find(e=>e.sourceId==='action:a').loopReturn,false,'Detached nodes are not loop returns');
    assert.equal(edges.find(e=>e.sourceId==='action:loop'&&e.sourcePort==='each').targetId,null);
});

test('Ctrl+Z undoes one whole group drag and leaves native text undo alone', async () => {
    const f = fixture();
    const draft = new WorkflowEditorDraft({}, {}, undefined, undefined, () => f.canvas);
    f.window.workflowDraftFor = () => draft;
    f.canvas.$el = {isConnected:true,getClientRects:() => [{}]};
    f.canvas.$wire = {};
    f.canvas.$refs.stage = {getBoundingClientRect:() => ({left:0,top:0,width:500,height:500})};
    const box = (node, base) => () => {
        const [x,y] = (node.style.transform || '').match(/-?\d+(?=px)/g)?.map(Number) || [0,0];
        return {left:base+x,top:y,width:96,height:96};
    };
    f.node.getBoundingClientRect = box(f.node, 100);
    const second = {...f.node, dataset:{workflowNodeId:'action:second'},style:{}};
    second.getBoundingClientRect = box(second, 400);
    f.canvas.nodeElements = () => [f.node,second];
    f.canvas.selectedNodeIds = ['action:test','action:second'];
    f.canvas.startNodeDrag(f.event());
    f.canvas.moveCanvas(f.event(70,80));
    f.canvas.moveCanvas(f.event(90,110));
    f.canvas.stopCanvasInteraction(f.event(90,110));
    assert.equal(draft.undoStack.length, 1);
    assert.equal(second.getBoundingClientRect().left, 480);
    let handled = 0;
    const event = {ctrlKey:true,code:'KeyZ',key:'я',preventDefault() {handled++;}};
    await f.canvas.clipboardShortcut({...event,target:{closest:() => true}});
    assert.equal(handled, 0);
    await f.canvas.clipboardShortcut({...event,target:{closest:() => false}});
    assert.equal(handled, 1);
    assert.equal(f.node.getBoundingClientRect().left, 100);
    assert.equal(second.getBoundingClientRect().left, 400);
    assert.equal(draft.undoStack.length, 0);
});

test('local edge projection recognizes done feedback, multiple starts and empty output ports', () => {
    const canvas=localDraft([{id:'before',type:'workflow_delay'},{id:'loop',type:'workflow_loop'}],[
        {sourceId:'trigger:two',sourcePort:'output',targetId:'action:before'},
        {sourceId:'action:before',sourcePort:'output',targetId:'action:loop'},
        {sourceId:'action:loop',sourcePort:'done',targetId:'action:before'},
    ]);
    const edges=canvas.canvasEdges([],['trigger','trigger:two']);
    assert.equal(edges.find(e=>e.sourcePort==='done').loopReturn,false);
    assert.equal(edges.find(e=>e.sourcePort==='done').loopFeedback,true);
    const layout = canvas.graphLayout(['trigger','trigger:two','action:before','action:loop'],edges.filter(e=>e.targetId).map(e=>({
        source:e.sourceId,target:e.targetId,port:e.sourcePort,loopReturn:e.loopReturn,loopFeedback:e.loopFeedback,
    })));
    assert.ok(layout,'Smooth feedback must still be excluded from acyclic layout ordering');
    assert.ok(layout['action:loop'].x > layout['action:before'].x);
    assert.equal(edges.find(e=>e.sourcePort==='each').targetId,null);
    assert.equal(edges.find(e=>e.sourceId==='trigger').targetId,null);
    assert.equal(edges.find(e=>e.sourceId==='trigger:two').targetId,'action:before');
});

test('an empty loop body offers a replace tile without a frame or stored placeholder action', () => {
    const canvas=localDraft([{id:'loop',type:'workflow_loop'}],[]);
    const each=canvas.canvasEdges([],['trigger']).find(edge=>edge.sourceId==='action:loop'&&edge.sourcePort==='each');
    assert.equal(each.targetId,null);
    for (const name of ['vendor/filament-workflows/components/workflows/edge-controls']) {
        const view=readFileSync(new URL('../../resources/views/'+name+'.blade.php',import.meta.url),'utf8');
        assert.doesNotMatch(view,/workflow-loop-frame|Замените меня|Действие цикла/);
        assert.match(view,/workflow-loop-replace/);assert.match(view,/Заменить/);
        assert.match(view,/Добавить действие в цикл/);
        assert.match(view,/openEdgePalette\(edge.sourceId, ?edge.sourcePort, ?edge.targetId\)/);
    }
});

test('done continuation and nested returns stay orthogonal without changing loop semantics', () => {
    const edge = (source,port,target) => ({sourceId:'action:'+source,sourcePort:port,targetId:'action:'+target});
    const actions = [{id:'outer',type:'workflow_loop'},{id:'inner',type:'workflow_loop'},
        {id:'body',type:'workflow_delay'},{id:'telegram',type:'telegram_send_message'}];
    const connections = [edge('outer','each','inner'),edge('inner','each','body'),edge('body','output','inner'),
        edge('inner','done','outer'),edge('outer','done','telegram'),edge('telegram','output','inner')];
    const canvas = localDraft(actions,connections);
    const projected = canvas.canvasEdges([],['trigger']).slice(0,connections.length);
    assert.deepEqual(Array.from(projected,e=>e.loopReturn),[false,false,true,true,false,false]);
    assert.deepEqual(Array.from(projected,e=>e.loopFeedback),[false,false,false,false,true,false]);
    for (const e of projected) {
        const route = canvas.connectionRoute({x:100,y:300},{x:350,y:80},e.loopReturn);
        assertOrthogonal(route.d,e.loopReturn ? 4 : 2);
        assert.equal(route.route.kind,e.loopReturn ? 'loop' : 'curve');
    }
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
    const source = readFileSync(new URL('../../resources/js/workflows.js',import.meta.url),'utf8');
    assert.doesNotMatch(css,/workflow-node-edge--(?:yes|no)/);
    assert.doesNotMatch(source,/marker-end|workflow-edge-arrow-/);
    assert.match(source,/const completed = !preview && this\.edgeCompleted/);
});

test('canvas keeps its dotted grid and separates neutral port dots from the hit area', () => {
    const css = readFileSync(new URL('../../resources/css/filament-workflows.css',import.meta.url),'utf8');
    const grid = readFileSync(new URL('../../resources/views/filament/workflow-builder/workflow-canvas-grid.blade.php',import.meta.url),'utf8');
    assert.doesNotMatch(css,/radial-gradient\(circle/,'Do not reintroduce fractionally rounded CSS background tiles');
    assert.match(css,/\.workflow-canvas-grid\s*\{[^}]*position:\s*absolute;[^}]*pointer-events:\s*none;/s);
    assert.match(css,/\.dark \.workflow-canvas-grid\s*\{[^}]*fill:\s*#687181;/s);
    assert.match(grid,/aria-hidden="true" focusable="false" x-id="\['workflow-grid'\]" wire:ignore/);
    assert.match(grid,/patternUnits="userSpaceOnUse"/);
    assert.match(grid,/x-bind:width="gridSpacing" x-bind:height="gridSpacing"/);
    assert.match(grid,/x-bind:pattern-transform\.camel="gridTransform\(\)"/);
    assert.match(grid,/x-bind:cx="gridSpacing \/ 2" x-bind:cy="gridSpacing \/ 2"/);
    assert.match(grid,/x-bind:fill="`url\(#\$\{\$id\('workflow-grid'\)\}\)`"/);
    assert.match(grid,/x-bind:opacity="gridOpacity\(\)"/);
    assert.match(css,/\.workflow-node-port--input::before\s*\{[^}]*width:\s*10px;[^}]*height:\s*10px;[^}]*border-radius:\s*50%;/s);
    assert.match(css,/\.workflow-node-port--input\s*\{[^}]*width:\s*14px !important;[^}]*height:\s*20px !important;/s);
    assert.match(css,/\.workflow-node-port--output\s*\{[^}]*width:\s*14px !important;[^}]*height:\s*20px !important;/s);
    assert.match(css,/\.dark [^{]+\.workflow-node-port::before\s*\{ border-color: #b4c6d8;[^}]*\}/);
    assert.match(css,/\[data-connection-source="true"\]\.workflow-node-port--output::before/);
    const buttonRule=css.match(/button\.workflow-node-port\s*\{([^}]+)\}/)?.[1];
    assert.ok(buttonRule);
    assert.doesNotMatch(buttonRule,/(?:^|;)\s*(?:width|height):/);
    assert.match(buttonRule,/--workflow-port-overhang:\s*9px/);
    assert.match(css,/right:\s*calc\(-1 \* var\(--workflow-port-overhang, 7px\)\)/);
    assert.match(css,/left:\s*calc\(-1 \* var\(--workflow-port-overhang, 7px\)\)/);
    assert.equal(10 / 2 - 7, 14 / 2 - 9, 'larger clickable rectangles retain their old horizontal center');
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
            ['curve', {kind:'curve',x:133,y:-117}, {x:160,y:-117}],
            ['exit', {kind:'loop',exitX:133,entryX:17,y:-117}, {exitX:160,entryX:17,y:-117}],
            ['entry', {kind:'loop',exitX:133,entryX:17,y:-117}, {exitX:133,entryX:40,y:-117}],
            ['lane', {kind:'loop',exitX:133,entryX:17,y:-117}, {exitX:133,entryX:17,y:-140}],
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

test('connections are crisp without changing node borders or edge hit targets', () => {
    const css = readFileSync(new URL('../../resources/css/filament-workflows.css',import.meta.url),'utf8');
    assert.match(css, /\.workflow-node-edge\s*\{[^}]*stroke-width:\s*1;[^}]*vector-effect:\s*non-scaling-stroke;/s);
    assert.match(css, /border:\s*1px solid #9da6b3 !important;/);
    assert.doesNotMatch(css, /border:\s*2px solid #9da6b3/);
    assert.match(css, /\.workflow-node-card--selected\s*\{\s*outline:\s*1px solid #339dc7/);
    assert.match(css, /\.workflow-node-edge\.is-selected\s*\{\s*stroke-width:\s*2;/);
    assert.match(css, /\.workflow-node-edge-hit\s*\{[^}]*stroke-width:\s*16;[^}]*pointer-events:\s*stroke;/s);
    assert.match(css,/\.workflow-node-edge\s*\{[^}]*shape-rendering:\s*crispEdges;[^}]*filter:\s*none;/s);
    assert.doesNotMatch(css,/\.workflow-node-edge(?:\.is-selected)?\s*\{[^}]*drop-shadow/s);
});

test('new branches start from output markers while existing edges keep hover controls', () => {
    const card = readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflows/action-card.blade.php', import.meta.url), 'utf8');
    const edges = readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflows/edge-controls.blade.php', import.meta.url), 'utf8');
    const css = readFileSync(new URL('../../resources/css/filament-workflows.css', import.meta.url), 'utf8');
    assert.match(card, /workflow-node-port--output-\{\{ \$port \}\}.*startConnection\('action:' \+ @js\(\$actionId\), @js\(\$port\), \$event\)/s);
    assert.match(css, /button\.workflow-node-port--output:hover::before[^{]*\{[^}]*border-color:\s*#2fa7d2/s);
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
    const source = readFileSync(new URL('../../resources/js/workflows.js',import.meta.url),'utf8');
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
    const source = readFileSync(new URL('../../resources/js/workflows.js', import.meta.url), 'utf8');
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
    const source = readFileSync(new URL('../../resources/js/workflows.js', import.meta.url), 'utf8');
    const code = source.slice(source.indexOf('controls.filter((button) => !button.dataset.workflowEdgeTarget)'), source.indexOf('        controls.forEach((button) => {'));
    const button = {dataset:{workflowEdgeSource:'trigger',workflowEdgeBranch:'true'}, getBoundingClientRect:()=>({width:22})};
    const card = {querySelector:()=>null,getBoundingClientRect:()=>({left:0,right:100,top:0,height:100})};
    const paths = [], positions = [];
    runInNewContext(code, {controls:[button],nodeById:new Map([['trigger',{querySelector:()=>card}]]),sourceSelector:()=>'',stageRect:{left:0,top:0},scale:1,paths,positionControl:(_button,x,y)=>positions.push([x,y]),maxX:0,maxY:0});
    assert.deepEqual(paths, []);
    assert.deepEqual(positions, [[112,78]]);
});

test('an empty loop has completion above and its replace tile on the right', () => {
    for (const scale of [.25,1,2]) {
        const f=routingFixture([{id:'action:loop',x:100,y:100}],
            [{source:'action:loop',port:'done',target:''},{source:'action:loop',port:'each',target:''}],scale);
        const paths=f.draw();
        assert.equal(paths.length,3);
        assert.equal(f.controls[0].style.left,'148px');
        assert.ok(parseFloat(f.controls[0].style.top)<100);
        assert.equal(f.controls[1].style.left,'398px');
        assert.equal(f.controls[1].style.top,'148px');
        const returnPath=f.elements().find(el=>el.attributes['data-workflow-loop-return']==='true');
        const points=assertOrthogonal(returnPath.attributes.d,3);
        assert.deepEqual(points.at(-1),{x:148,y:196});
        assert.equal(points.at(-2).x,148);assert.ok(points.at(-2).y-196>=112);
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

test('the first trigger replaces the event slot and later starts use separate non-overlapping positions', () => {
    const f = fixture();
    const canvas = f.canvas;
    canvas.scale = 0.5;
    const stageRect = {left:100,top:50};
    const slot = {getBoundingClientRect:() => ({left:150,top:150})};
    canvas.$refs.stage = {getBoundingClientRect:()=>stageRect,querySelector:()=>slot};
    let points = {'trigger':{x:0,y:0},'action:unrelated':{x:500,y:420}};
    let ids = ['trigger','action:unrelated'];
    canvas.nodeElements = () => ids.map(id => ({dataset:{workflowNodeId:id},
        querySelector:()=>({getBoundingClientRect:()=>({width:48,height:48})})}));
    canvas.cardPositions = () => structuredClone(points);
    canvas.restoreCardPositions = desired => { points = desired; };
    canvas.applyNodeSelection = () => {};
    canvas.captureTriggerAdd();
    canvas.placeCreatedNode({nodeId:'trigger'});
    assert.deepEqual(JSON.parse(JSON.stringify(points.trigger)),{x:100,y:200});
    ids.push('trigger:two');
    points['trigger:two'] = {x:0,y:0};
    canvas.placeCreatedNode({nodeId:'trigger:two'});
    assert.deepEqual(JSON.parse(JSON.stringify(points['trigger:two'])),{x:100,y:420});
    assert.deepEqual(points['action:unrelated'],{x:500,y:420});
    points['action:unrelated'] = {x:100,y:640};
    ids.push('trigger:three');
    points['trigger:three'] = {x:0,y:0};
    canvas.placeCreatedNode({nodeId:'trigger:three'});
    assert.deepEqual(JSON.parse(JSON.stringify(points['trigger:three'])),{x:100,y:860});
    assert.deepEqual(JSON.parse(JSON.stringify(points['trigger:two'])),{x:100,y:420});
});

test('the ordinary add-node menu offers actions; starts have their own button', () => {
    const f = fixture();
    f.canvas.$wire = {$set() {}};
    f.canvas.preparePalette(null);
    assert.equal(f.events().at(-1).detail.mode, 'action');
    const markup = readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflow-builder.blade.php',import.meta.url),'utf8');
    assert.match(markup, /class="workflow-canvas-fit workflow-canvas-add-trigger"[^>]*x-show="Boolean\(editorWire\.trigger\)"/);
    assert.match(markup, /x-on:click\.stop="pendingDropPosition = null; pendingInsertion = null; workflowEditorCall\(\$wire, 'beginTriggerAdd'\)"/);
});

test('old overlapping starts are separated without moving the first start or actions', () => {
    const {canvas} = fixture();
    let points = {'trigger':{x:100,y:200},'trigger:two':{x:100,y:360},
        'trigger:three':{x:100,y:420},'action:unrelated':{x:800,y:420}};
    canvas.nodeElements = () => ['trigger','trigger:two','trigger:three','action:unrelated'].map(id => ({
        dataset:{workflowNodeId:id},querySelector:()=>({})}));
    canvas.cardPositions = () => structuredClone(points);
    canvas.restoreCardPositions = desired => {points = desired;};
    canvas.separateOverlappingTriggers();
    assert.deepEqual(points.trigger,{x:100,y:200});
    assert.deepEqual(points['action:unrelated'],{x:800,y:420});
    assert.ok(points['trigger:two'].y-points.trigger.y >= 190);
    assert.ok(points['trigger:three'].y-points['trigger:two'].y >= 190);
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
    const CustomEvent = class { constructor(type, init = {}) { this.type = type; this.detail = init.detail; } };
    const window = { addEventListener() {}, dispatchEvent(event) { events.push({type:event.type,detail:event.detail}); }, requestAnimationFrame(callback) { callback(); }, setTimeout(callback) { callback(); return 1; }, clearTimeout() {} };
    window.sessionStorage = {getItem: key => viewportStorage.get(key) ?? null, setItem: (key, value) => viewportStorage.set(key, value)};
    let mutationCallback, observedOptions, resizeCallback;
    const resizedElements = new Set();
    runInNewContext(readFileSync(new URL('../../resources/js/workflows.js', import.meta.url), 'utf8'), {
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
    assert.equal(layout.body.y,layout.loop.y);
    assert.ok(layout.done.y < layout.body.y);
    edges[3].loopReturn = false;
    assert.equal(canvas.graphLayout(ids,edges),null);
});

test('return path has sharp corners below the body and ends at the loop input', () => {
    const {canvas} = fixture();
    const route = canvas.connectionRoute({x:600,y:160},{x:100,y:160},true,{kind:'loop',exitX:624,entryX:76,y:360});
    assert.match(route.d,/^M 600 160 L/);
    assertOrthogonal(route.d,4);
    assert.equal(route.handles.length,1);
    assert.equal(route.handles[0].part,'lane');
    assert.match(route.d,/L 100 160$/);
    assert.equal(route.y,360);
    assert.equal(route.right,624);
});

test('the replace tile closes visually without adding a runtime action or saved connection', () => {
    let expected;
    for (const scale of [.5,1,2]) {
        const fixture = routingFixture([{id:'action:loop',x:100,y:100}],
            [{source:'action:loop',target:'',port:'each'}],scale);
        const paths = fixture.draw();
        assert.equal(paths.length,2);
        assertOrthogonal(paths[0],0);
        assertOrthogonal(paths[1],3);
        assert.deepEqual(Object.keys(fixture.canvas.edgeRoutes),[]);
        if (expected) assert.deepEqual(paths,expected);
        expected = paths;
    }
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
    assert.equal(control.style.top,'308px');
    assert.ok(elements.some(e => e.attributes.d?.includes('308')));
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
            const upper = /-(yes|done)$/.test(selector), lower = /-no$/.test(selector);
            if (selector === '.workflow-node-port--loop-return') return {getBoundingClientRect:()=>screenRect({left:box.x+48,right:box.x+48,top:box.y+96,bottom:box.y+96})};
            const x = upper || lower ? box.x+48 : box.x+(input?(box.portInset??0):96-(box.portInset??0));
            const y = box.y+(upper ? 0 : lower ? 96 : 48);
            return {getBoundingClientRect:()=>screenRect({left:x,right:x,top:y,bottom:y})};
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


test('condition empty outputs still depart vertically above and below the card at every zoom', () => {
    for (const scale of [0.25,0.5,1,2]) for (const ports of [['yes','no']]) {
        const f=routingFixture([{id:'action:branch',x:100,y:260}],ports.map(port=>({source:'action:branch',port,target:''})),scale);
        const paths=f.draw();
        assert.equal(paths.length,2);
        for (const [index,direction] of [-1,1].entries()) {
            const startY=260+(direction<0?0:96),controlY=startY+direction*(11+(192-11)*.8);
            assert.ok(Math.abs(parseFloat(f.controls[index].style.left)-148)<0.000001);
            assert.ok(Math.abs(parseFloat(f.controls[index].style.top)-controlY)<0.000001);
            const points=assertOrthogonal(paths[index],0);
            assert.deepEqual(points[0],{x:148,y:startY});
            assert.ok(points.every(point=>Math.abs(point.x-148)<0.000001));
            assert.ok((points.at(-1).y-startY)*direction>0);
        }
    }
});

test('ordinary backward links use one rectangular detour and ignore other cards and captions', () => {
    const boxes = [{id:'loop',x:140,y:350},{id:'http',x:500,y:90},{id:'if',x:780,y:90},{id:'done',x:440,y:580}];
    const f = routingFixture(boxes,[{source:'if',target:'loop',port:'no'}]);
    const [path] = f.draw();
    assertOrthogonal(path,4);
    boxes[1].y = 900;
    boxes[3].x = 650;
    assert.equal(f.draw()[0],path);
});

test('loop side legs stay angular with distinct return lanes, ignoring other nodes', () => {
    const boxes = [{id:'loop',x:140,y:160},{id:'body',x:780,y:160},{id:'side',x:890,y:340},{id:'below',x:90,y:470}];
    const f = routingFixture(boxes,[{source:'body',target:'loop',port:'yes',loopReturn:true},{source:'body',target:'loop',port:'no',loopReturn:true}]);
    const paths = f.draw();
    paths.forEach(path=>{
        assertOrthogonal(path,4);
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
    assertOrthogonal(blocked,0);
    assert.equal(blocked,'M 196 198 L 720 198');
    boxes[1].y = 400;
    const [clear] = f.draw();
    assertOrthogonal(clear,0);
    assert.equal(clear,blocked);
    assert.deepEqual(Object.keys(f.canvas.positions),[]);
});

test('parallel start nodes join a long-caption node with at most two right-angle bends', () => {
    const boxes = [{id:'schedule',x:100,y:100},{id:'manual',x:100,y:300},
        {id:'settings',x:350,y:100,captionLeft:80,captionRight:180}];
    const edges = [{source:'schedule',target:'settings'},{source:'manual',target:'settings'}];
    const expected = routingFixture(boxes,edges).draw();
    for (const scale of [.5,1,2]) {
        const paths = routingFixture(boxes,edges,scale).draw();
        assert.deepEqual(paths,expected);
        paths.forEach(path=>{
            assertOrthogonal(path,2);
        });
    }
});

test('real port centres inset into card borders do not force ordinary connections into detours', () => {
    const boxes = [{id:'schedule',x:100,y:100},{id:'manual',x:100,y:300},{id:'settings',x:350,y:100}]
        .map(box=>({...box,captionLeft:48,captionRight:144,portInset:2}));
    const edges = [{source:'schedule',target:'settings'},{source:'manual',target:'settings'}];
    for (const scale of [.5,1,2]) {
        routingFixture(boxes,edges,scale).draw().forEach(path=>{
            assertOrthogonal(path,2);
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

test('return insertion extends the body to the right without shifting the loop or unrelated nodes', () => {
    for (const scale of [.5,1,2]) {
        const f = fixture();
        f.canvas.scale = scale;
        let positions = {loop:{x:48,y:100},body:{x:500,y:100},unrelated:{x:100,y:2000}};
        let source = 'body';
        f.canvas.cardPositions = () => positions;
        f.canvas.$refs.stage = {querySelectorAll:()=>[
            {dataset:{workflowEdgeSource:source,workflowEdgeTarget:'loop',workflowLoopReturn:'true'}},
        ]};
        f.canvas.restoreCardPositions = desired => {positions=desired;};
        for (const nodeId of ['first','second']) {
            const before = JSON.parse(JSON.stringify(positions));
            f.canvas.captureInsertion(source,'loop');
            assert.equal(f.canvas.pendingInsertion.loopReturn,true);
            assert.equal(f.canvas.pendingInsertion.feedback,true);
            f.canvas.placeInsertedNode({sourceId:source,targetId:'loop',sourcePort:'output',nodeId});
            assert.equal(positions[nodeId].x,positions[source].x+250);
            assert.equal(positions[nodeId].y,positions[source].y);
            for (const [id,point] of Object.entries(before)) assert.deepEqual({...positions[id]},point);
            source = nodeId;
        }
    }
});

test('return insertion keeps the row when the next position is occupied and honors an explicit drop', () => {
    const f = fixture();
    const nodes = {loop:{x:48,y:100},body:{x:500,y:100},occupied:{x:790,y:100}};
    let placed;
    f.canvas.restoreCardPositions = desired => {placed=desired;};
    const pending = {sourceId:'body',targetId:'loop',nodes,edges:[],feedback:true,loopReturn:true};
    f.canvas.pendingInsertion = {...pending};
    f.canvas.placeInsertedNode({sourceId:'body',targetId:'loop',sourcePort:'output',nodeId:'new'});
    assert.equal(placed.new.x,1000);
    assert.equal(placed.new.y,100);
    assert.deepEqual(placed.occupied,nodes.occupied);
    f.canvas.pendingInsertion = {...pending,dropPosition:{x:400,y:700}};
    f.canvas.placeInsertedNode({sourceId:'body',targetId:'loop',sourcePort:'output',nodeId:'new'});
    assert.deepEqual({...placed.new},{x:350,y:650});
    assert.deepEqual(placed.loop,nodes.loop);
    assert.deepEqual(placed.body,nodes.body);
});

test('inserting into smooth done feedback does not shift the earlier node or loop', () => {
    const f = fixture();
    const positions = {check:{x:48,y:100},loop:{x:500,y:100}};
    const controls = [
        {dataset:{workflowEdgeSource:'check',workflowEdgeTarget:'loop',workflowLoopReturn:'false',workflowLoopFeedback:'false'}},
        {dataset:{workflowEdgeSource:'loop',workflowEdgeTarget:'check',workflowLoopReturn:'false',workflowLoopFeedback:'true'}},
    ];
    f.canvas.cardPositions = () => positions;
    f.canvas.$refs.stage = {querySelectorAll:()=>controls};
    f.canvas.captureInsertion('loop','check');
    assert.equal(f.canvas.pendingInsertion.feedback,true);
    assert.equal(f.canvas.pendingInsertion.edges.length,1);
    let result;
    f.canvas.restoreCardPositions = desired => {result=desired;};
    f.canvas.placeInsertedNode({sourceId:'loop',targetId:'check',sourcePort:'done',nodeId:'new'});
    assert.deepEqual(result.check,positions.check);
    assert.deepEqual(result.loop,positions.loop);
    assert.ok(result.new.y > result.loop.y);
});

test('legacy loop branch routes adapt to vertical ports without rewriting saved data', () => {
    const saved = {kind:'loop',exitX:250,entryX:500,y:550};
    const boxes = [{id:'loop',x:100,y:350},{id:'telegram',x:550,y:80}];
    const edge = {source:'loop',target:'telegram',port:'done'};
    let expected;
    for (const scale of [.25,1,2]) {
        const f = routingFixture(boxes,[edge],scale);
        f.canvas.edgeRoutes[JSON.stringify(['loop','done','telegram'])] = saved;
        const [path] = f.draw();
        const points=assertOrthogonal(path,3);
        assert.equal(points[0].x,148);
        assert.equal(points[1].x,148);
        assert.ok(points[1].y<points[0].y);
        const normalized = path.replace(/-?\d+(?:\.\d+)?/g,value=>Number(value).toFixed(6));
        if (expected) assert.equal(normalized,expected);
        expected = normalized;
        assert.ok(Math.abs(parseFloat(f.controls[0].style.top)-326)<0.000001);
        assert.deepEqual(saved,{kind:'loop',exitX:250,entryX:500,y:550},'Rendering must not mutate saved workflow data');
    }
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
        const points=assertOrthogonal(path,4);
        assert.ok(points.some(point=>point.x===148&&point.y===360));
        assert.equal(points.at(-1).x,148);assert.equal(points.at(-1).y,196);
        assert.doesNotMatch(path,/NaN/);
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
        f.canvas.$refs.stage = {getBoundingClientRect:() => ({left:0,top:0})};
        f.node.getBoundingClientRect = () => ({left:14*scale,top:23*scale,width:96*scale,height:96*scale});
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

test('legacy loop handles and the ordinary spine move on one fixed axis in full grid cells', () => {
    const f = fixture(), edge = {sourceId:'action:source',sourcePort:'output',targetId:'action:test'};
    const key = f.canvas.edgeRouteKey(edge);
    const original = {kind:'loop',exitX:600,entryX:100,y:320};
    for (const [part,field] of [['exit','exitX'],['entry','entryX']]) {
        f.canvas.startEdgeRouteDrag(edge,original,f.event(100,100),part);
        f.canvas.moveCanvas(f.event(150,180));
        f.canvas.stopCanvasInteraction(f.event(150,180));
        assert.equal(f.canvas.edgeRoutes[key][field],original[field]+60);
        assert.equal(f.canvas.edgeRoutes[key].y,320);
    }
    f.canvas.startEdgeRouteDrag(edge,{kind:'curve',x:350,y:120},f.event(100,100));
    f.canvas.moveCanvas(f.event(150,180));
    f.canvas.stopCanvasInteraction(f.event(150,180));
    assert.equal(f.canvas.edgeRoutes[key].x,400);
    assert.equal(f.canvas.edgeRoutes[key].y,120);
    const curve = f.canvas.connectionRoute({x:100,y:50},{x:700,y:50},false,f.canvas.edgeRoutes[key]);
    assertOrthogonal(curve.d,4);
    assert.equal(curve.handles.length,1);
    assert.equal(curve.handles[0].part,'lane');
});

test('curve axis locks for a gesture and abandoned or irrelevant movement does not rewrite legacy paths', () => {
    const f=fixture(), edge={sourceId:'trigger',sourcePort:'output',targetId:'action:test'};
    const key=f.canvas.edgeRouteKey(edge);
    const legacy={kind:'curve',x:133,y:117};
    f.canvas.edgeRoutes[key]=legacy;
    f.canvas.startEdgeRouteDrag(edge,legacy,f.event(100,100));
    f.canvas.moveCanvas(f.event(128,105));
    f.canvas.moveCanvas(f.event(164,204));
    assert.equal(f.canvas.edgeRoutes[key].x,200);
    assert.equal(f.canvas.edgeRoutes[key].y,117);
    f.canvas.moveCanvas(f.event(100,225));
    f.canvas.stopCanvasInteraction(f.event(100,225));
    assert.equal(f.canvas.edgeRoutes[key].x,133);
    assert.equal(f.canvas.edgeRoutes[key].y,117);
    assert.equal(f.saves(),0);

    const loop={kind:'loop',exitX:133,entryX:17,y:117};
    f.canvas.edgeRoutes[key]=loop;
    f.canvas.startEdgeRouteDrag(edge,loop,f.event(100,100),'exit');
    f.canvas.moveCanvas(f.event(100,190));
    f.canvas.stopCanvasInteraction(f.event(100,190));
    assert.equal(f.canvas.edgeRoutes[key].exitX,133);
    assert.equal(f.saves(),0);
});

test('edge selection has no floating menu while direct manipulation remains available', () => {
    const view = readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflow-builder.blade.php', import.meta.url), 'utf8');
    const css = readFileSync(new URL('../../resources/css/filament-workflows.css', import.meta.url), 'utf8');
    const source = readFileSync(new URL('../../resources/js/workflows.js', import.meta.url), 'utf8');
    assert.doesNotMatch(view + css + source, /workflow-connection-menu|Перетащите линию или точки|Сбросить изгиб|resetSelectedEdgeRoute|reconnectSelectedEdge/);
    assert.match(view, /x-show="connecting \|\| connectionError"/);
    assert.match(source, /this\.startEdgeRouteDrag\(edge, route, event, handles\?\.\[0\]\?\.part\)/);
    assert.match(source, /this\.beginEdgeReconnect\(/);
});

test('clicking an edge selects it and cancellation restores the old route', () => {
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

test('canvas background keeps the ordinary arrow cursor before and during panning', () => {
    const css = readFileSync(new URL('../../resources/css/filament-workflows.css', import.meta.url), 'utf8');
    assert.match(css, /\.workflow-node-editor__viewport\s*\{[^}]*cursor:\s*default;/s);
    assert.match(css, /\.workflow-node-editor__viewport\.is-panning,\s*\.workflow-node-editor__viewport\.is-panning \*\s*\{[^}]*cursor:\s*default !important;/s);
});

test('Ctrl, Cmd and Shift drag select a rectangle instead of panning and retain the earlier selection', () => {
    for (const modifier of ['ctrlKey', 'metaKey', 'shiftKey']) {
        const f = fixture();
        f.target.closest = () => null;
        f.node.getBoundingClientRect = () => ({left:20,top:30,right:70,bottom:80});
        f.canvas.$refs.viewport.getBoundingClientRect = () => ({left:0,top:0});
        f.canvas.selectedNodeIds = ['old-selection'];
        f.canvas.startCanvasInteraction({...f.event(0,0), [modifier]:true});
        f.canvas.moveCanvas(f.event(100,100)); // Releasing the modifier cannot turn selection into panning.
        assert.deepEqual([...f.canvas.selectedNodeIds], ['old-selection', 'action:test']);
        assert.equal(f.canvas.canvasPan, null);
        assert.equal(f.canvas.routeDrag, null);
        assert.equal(f.canvas.translateX, 0);
        assert.equal(f.canvas.translateY, 0);
        f.canvas.stopCanvasInteraction(f.event(100,100));
        assert.equal(f.canvas.selectionBox, null);
        assert.equal(f.canvas.selectionOrigin, null);
        assert.equal(f.canvas.pointerId, null);
        assert.equal(f.saves(), 0);

        // The next plain drag still moves the canvas and leaves selected nodes in place.
        f.canvas.startCanvasInteraction(f.event(200,200));
        f.canvas.moveCanvas(f.event(260,230));
        f.canvas.stopCanvasInteraction(f.event(260,230));
        assert.equal(f.canvas.translateX, 60);
        assert.equal(f.canvas.translateY, 30);
        assert.deepEqual([...f.canvas.selectedNodeIds], ['old-selection', 'action:test']);
        assert.equal(f.saves(), 0);
    }
});

test('left drag on empty canvas pans 1:1 at every zoom without moving nodes or saving workflow data', () => {
    for (const scale of [.25, 1, 2]) {
        const f = fixture(), classes = new Set();
        f.target.closest = () => null;
        f.canvas.$refs.viewport.classList = {add: c => classes.add(c), remove: c => classes.delete(c)};
        f.canvas.$refs.viewport.hasPointerCapture = () => true;
        let releases = 0;
        f.canvas.$refs.viewport.releasePointerCapture = () => releases++;
        f.canvas.scale = scale;
        f.canvas.translateX = 120;
        f.canvas.translateY = -80;
        f.canvas.positions = {'action:test': {x:40,y:60}};
        f.canvas.selectedNodeIds = ['action:test'];
        const down = f.event(10,20);
        f.canvas.startCanvasInteraction(down);
        assert.equal(down.prevented, true);
        assert.equal(f.captures(), 1);
        assert.equal(classes.has('is-panning'), true);
        assert.equal(f.canvas.selectionBox, null);
        f.canvas.moveCanvas(f.event(70,65));
        assert.equal(f.canvas.translateX, 180);
        assert.equal(f.canvas.translateY, -35);
        f.canvas.stopCanvasInteraction({...f.event(70,65),type:'pointerup'});
        assert.equal(f.canvas.canvasPan, null);
        assert.equal(classes.has('is-panning'), false);
        assert.equal(releases, 1);
        assert.deepEqual(f.canvas.positions, {'action:test': {x:40,y:60}});
        assert.deepEqual(f.canvas.selectedNodeIds, ['action:test']);
        assert.equal(f.saves(), 0);
        assert.deepEqual(JSON.parse(f.viewportStorage.get(f.canvas.layoutKey + ':viewport')), {x:180,y:-35,scale});
        f.canvas.moveCanvas(f.event(100,100));
        assert.equal(f.canvas.translateX, 180);
    }
});

test('an empty click clears selection without moving the viewport or opening node settings', () => {
    const f = fixture();
    f.target.closest = () => null;
    f.canvas.selectedNodeIds = ['action:test'];
    f.canvas.startCanvasInteraction(f.event());
    f.canvas.moveCanvas(f.event(11,21));
    f.canvas.stopCanvasInteraction(f.event(11,21));
    assert.equal(f.canvas.translateX, 0);
    assert.equal(f.canvas.translateY, 0);
    assert.equal(f.canvas.selectedNodeIds.length, 0);
    assert.equal(f.saves(), 0);
    assert.equal(f.events().length, 0);
});

test('pan only follows its initial pointer and ignores wheel until the mouse is released', () => {
    const f = fixture();
    f.target.closest = () => null;
    f.canvas.startCanvasInteraction(f.event());
    f.canvas.startCanvasInteraction({...f.event(300,400),pointerId:2});
    f.canvas.moveCanvas({...f.event(400,500),pointerId:2});
    f.canvas.stopCanvasInteraction({...f.event(),pointerId:2});
    f.canvas.stopCanvasPan(true, 2);
    assert.equal(f.canvas.canvasPan.pointerId, 1);
    assert.equal(f.canvas.translateX, 0);
    f.canvas.onCanvasWheel({...f.event(),deltaX:0,deltaY:100});
    assert.equal(f.canvas.translateY, 0);
    f.canvas.moveCanvas(f.event(40,60));
    f.canvas.moveCanvas({...f.event(300,400),buttons:0});
    assert.equal(f.canvas.canvasPan, null);
    assert.equal(f.canvas.translateX, 30);
    assert.equal(f.canvas.translateY, 40);
});

test('pointer cancellation, lost capture and window blur stop pan without clearing selection', () => {
    for (const finish of ['cancel','capture','blur','destroy']) {
        const f = fixture();
        f.target.closest = () => null;
        f.canvas.selectedNodeIds = ['action:test'];
        f.canvas.startCanvasInteraction(f.event());
        if (finish === 'cancel') f.canvas.stopCanvasInteraction({...f.event(),type:'pointercancel'});
        else if (finish === 'capture') f.canvas.stopCanvasPan(true, 1);
        else if (finish === 'destroy') f.canvas.destroy();
        else f.canvas.stopCanvasPan(true);
        assert.equal(f.canvas.canvasPan, null);
        assert.deepEqual(f.canvas.selectedNodeIds, ['action:test']);
        f.canvas.moveCanvas(f.event(100,200));
        assert.equal(f.canvas.translateX, 0);
    }
    for (const path of ['../../resources/views/vendor/filament-workflows/components/workflow-builder.blade.php',
        '../../resources/views/filament/workflow-builder/workflow-execution-canvas.blade.php']) {
        const view = readFileSync(new URL(path, import.meta.url), 'utf8');
        assert.match(view, /x-on:blur.window="stopCanvasPan\(true\)"/);
        assert.match(view, /x-on:lostpointercapture="stopCanvasPan\(true, \$event.pointerId\)"/);
        assert.match(view, /x-on:wheel="onCanvasWheel\(\$event\)"/);
    }
});

test('pan never hijacks node controls, editable fields or another active canvas gesture', () => {
    for (const control of ['button','a','input','textarea','select','[role="button"]','[contenteditable="true"]',
        '[draggable="true"]','.workflow-node-port','.workflow-canvas-note','.workflow-canvas-area__header','.workflow-canvas-area__resize']) {
        const f = fixture();
        f.target.closest = selector => selector.split(', ').includes(control) ? {} : null;
        f.canvas.startCanvasInteraction(f.event());
        assert.equal(f.canvas.canvasPan, null, control);
        assert.equal(f.captures(), 0, control);
    }
    for (const gesture of ['connecting','routeDrag','areaDrag','pendingEdgeDrag','selectionOrigin']) {
        const f = fixture();
        f.target.closest = () => null;
        f.canvas[gesture] = {};
        f.canvas.startCanvasInteraction(f.event());
        assert.equal(f.canvas.canvasPan, null, gesture);
    }
    for (const extras of [{button:1},{button:2},{isPrimary:false}]) {
        const f = fixture();
        f.target.closest = () => null;
        f.canvas.startCanvasInteraction({...f.event(),...extras});
        assert.equal(f.canvas.canvasPan, null);
    }
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
    assert.ok(f.observedOptions().attributeFilter.includes('data-workflow-loop-feedback'));
    f.mutate([{type:'attributes',attributeName:'style',target:f.node}]);
    assert.equal(f.node.style.transform, 'translate3d(40px, 180px, 0px)');
    assert.equal(refreshes, 1);
    f.mutate([{type:'attributes',target:svg}]);
    assert.equal(refreshes, 1, 'Drawing SVG edges must not trigger another refresh');
});

test('a save during a partial render preserves coordinates for nodes still in the draft', () => {
    const f = fixture();
    const wire = localDraft([{id:'test'}, {id:'other'}], []).$wire;
    f.canvas.$wire = wire;
    const offsets = {'action:test':{x:40,y:180}, 'action:other':{x:20,y:-220}};
    const points = {trigger:{x:0,y:0}, 'action:test':{x:200,y:180}, 'action:other':{x:400,y:-220}};
    Object.assign(wire.definition, {canvas_layout:offsets, canvas_positions:points});
    const original = JSON.stringify({version:1,nodes:offsets,edges:{}});
    let stored = original;
    f.window.localStorage = {getItem:() => stored, setItem:(_, value) => {stored = value;}};
    f.canvas.restoreNodeLayout();
    f.canvas.$refs.stage = {getBoundingClientRect:() => ({left:0,top:0,width:800,height:600})};
    f.node.getBoundingClientRect = () => ({left:200,top:180,width:96,height:96});
    f.persistLayout();
    assert.deepEqual(wire.definition.canvas_positions, points);
    assert.deepEqual(wire.definition.canvas_layout, offsets);
    assert.deepEqual(structuredClone(f.canvas.positions), offsets);
    assert.equal(stored, original);

    const card = (id, x, y) => ({...f.node, dataset:{workflowNodeId:id},
        getBoundingClientRect:() => ({left:x,top:y,width:96,height:96})});
    f.canvas.nodeElements = () => [card('trigger', 0, 0), card('action:test', 250, 180), card('action:other', 400, -220)];
    f.canvas.saveNodeLayout = f.persistLayout;
    f.canvas.restoreCardPositions();
    assert.equal(wire.definition.canvas_positions['action:test'].x, 250);
    assert.equal(wire.definition.canvas_positions['action:other'].y, -220);
});

test('a replacement canvas recovers unsaved coordinates and routes after becoming measurable', () => {
    const f = fixture(), hooks = new Map(), root = {contains: el => el.isConnected};
    f.window.Livewire = {hook(name, callback) {
        if (!hooks.has(name)) hooks.set(name, new Set());
        hooks.get(name).add(callback);
        return () => hooks.get(name).delete(callback);
    }};
    const wire = localDraft([], []).$wire;
    delete wire.workflowActions;
    const edge = JSON.stringify(['trigger','output','action:test']);
    const route = {kind:'curve',x:400,y:240};
    const configure = (canvas, base, visible) => {
        const node = {...f.node, style:{}, getBoundingClientRect() {
            if (!visible()) return {left:0,top:0,width:0,height:0};
            const [x,y] = (this.style.transform || '').match(/-?\d+(?=px)/g)?.map(Number) || [0,0];
            return {left:base+x,top:y,width:96,height:96};
        }};
        Object.assign(canvas, {
            $wire:wire, $el:{isConnected:true,closest:() => root},
            $refs:{stage:{querySelector:() => null, querySelectorAll:() => [],
                getBoundingClientRect:() => ({left:0,top:0,width:800,height:600})}},
            $nextTick:callback => callback(), nodeElements:() => [node],
            restoreViewport:() => true, scheduleGraphRefresh:() => {}, scheduleGraphRefreshAfterPaint:() => {},
        });
        return node;
    };
    const original = configure(f.canvas, 100, () => true);
    f.canvas.initializeCanvas();
    f.canvas.positions['action:test'] = {x:100,y:180};
    f.canvas.edgeRoutes[edge] = route;
    f.canvas.applyNodePositions();
    for (const hook of hooks.get('morph')) hook({el:root});
    f.canvas.$el.isConnected = false;
    f.canvas.destroy();
    assert.equal(original.getBoundingClientRect().left, 200);

    let visible = false;
    const replacement = f.window.workflowNodeCanvas(f.canvas.layoutKey, {}, {});
    const node = configure(replacement, 380, () => visible);
    replacement.initializeCanvas();
    for (const hook of hooks.get('morphed')) hook({el:root});
    visible = true;
    f.resize();
    assert.equal(node.getBoundingClientRect().left, 200);
    assert.equal(node.getBoundingClientRect().top, 180);
    assert.equal(wire.definition.canvas_positions['action:test'].x, 200);
    assert.deepEqual(structuredClone(replacement.edgeRoutes[edge]), route);
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

test('Ctrl rectangle selects several nodes and group drag preserves relative spacing at zoom', () => {
    const f = fixture();
    const second = {...f.node, dataset:{workflowNodeId:'action:second'},style:{},getBoundingClientRect:()=>({left:100,top:40,right:140,bottom:80})};
    f.node.getBoundingClientRect=()=>({left:20,top:40,right:60,bottom:80});
    f.canvas.nodeElements=()=>[f.node,second];
    f.canvas.$refs.viewport.getBoundingClientRect=()=>({left:0,top:0});
    f.canvas.startBoxSelection({...f.event(0,0),ctrlKey:true});
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
    assert.equal(boxes.inserted.x+f.canvas.positions.inserted.x,250);
    assert.ok(Math.abs(boxes.target.x+f.canvas.positions.target.x-499.2)<1e-6);
    assert.ok(Math.abs(boxes.tail.x+f.canvas.positions.tail.x-691.2)<1e-6);
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

test('orthogonal routing uses one middle handle and never mutates legacy route data', () => {
    const {canvas}=fixture();
    const saved={kind:'curve',x:320,y:500};
    const route=canvas.connectionRoute({x:100,y:60},{x:700,y:260},false,saved);
    const points=assertOrthogonal(route.d,2);
    assert.deepEqual(points,[{x:100,y:60},{x:320,y:60},{x:320,y:260},{x:700,y:260}]);
    assert.equal(route.handles.length,1);
    assert.equal(route.handles[0].part,'spine');
    assert.deepEqual(saved,{kind:'curve',x:320,y:500});
    for (const x of [-1000,2000]) assertOrthogonal(canvas.connectionRoute({x:100,y:60},{x:700,y:260},false,{kind:'curve',x,y:500}).d,2);
});

test('dragging an aligned middle lane adds only one rectangular detour without moving endpoints', () => {
    const f=fixture(),edge={sourceId:'trigger',sourcePort:'output',targetId:'action:test'};
    const start={x:100,y:60},end={x:700,y:60};
    const initial=f.canvas.connectionRoute(start,end,false);
    assertOrthogonal(initial.d,0);
    f.canvas.startEdgeRouteDrag(edge,initial.route,f.event(100,100),initial.handles[0].part);
    f.canvas.moveCanvas(f.event(135,155));
    f.canvas.stopCanvasInteraction(f.event(135,155));
    const saved=f.canvas.edgeRoutes[f.canvas.edgeRouteKey(edge)];
    assert.equal(saved.x,400);
    assert.equal(saved.y,120);
    const points=assertOrthogonal(f.canvas.connectionRoute(start,end,false,saved).d,4);
    assert.deepEqual(points[0],start);
    assert.deepEqual(points.at(-1),end);
    assert.deepEqual(Object.keys(f.canvas.positions),[]);
});

test('top and bottom source directions remain vertical for connected and dragged routes', () => {
    const {canvas}=fixture();
    for (const direction of [-1,1]) for (const end of [{x:700,y:60},{x:700,y:500},{x:50,y:60},{x:50,y:500}]) {
        const start={x:300,y:280};
        const route=canvas.connectionRoute(start,end,false,null,{x:0,y:direction});
        const points=assertOrthogonal(route.d,3);
        assert.deepEqual(points[0],start);
        assert.deepEqual(points.at(-1),end);
        assert.equal(points[1].x,start.x);
        assert.ok((points[1].y-start.y)*direction>0);
        assert.equal(points.at(-2).y,end.y);
        assert.ok(points.at(-2).x<end.x);
        assert.equal(route.handles.length,1);
        assert.equal(route.handles[0].part,'lane');
    }
});

test('new upper and lower branches use the standard rightward step without shifting existing nodes', () => {
    for (const [port,direction] of [['yes',-1],['done',-1],['no',1]]) {
        const f=fixture(),before={branch:{x:200,y:400},other:{x:900,y:100}};
        f.canvas.pendingInsertion={sourceId:'branch',targetId:null,nodes:before,edges:[]};
        let placed;f.canvas.restoreCardPositions=points=>{placed=points;};
        f.canvas.placeInsertedNode({sourceId:'branch',targetId:null,sourcePort:port,nodeId:'new'});
        assert.deepEqual(placed.branch,before.branch);assert.deepEqual(placed.other,before.other);
        assert.equal(placed.new.x,450);
        assert.equal(placed.new.y,Math.round((400+direction*240*.8)/10)*10);
    }
});

test('fit view includes upper and lower branch pluses without moving the node', () => {
    const f=fixture();
    const card={getBoundingClientRect:()=>({left:100,top:100,right:196,bottom:196})};
    f.canvas.nodeElements=()=>[{querySelector:selector=>selector==='[data-workflow-node-card]'?card:null}];
    f.canvas.$refs.stage={getBoundingClientRect:()=>({left:0,top:0}),querySelector:()=>null};
    f.canvas.$refs.viewport.clientWidth=800;f.canvas.$refs.viewport.clientHeight=600;
    const bounds=[{left:137,right:159,top:-103,bottom:-81,width:22,height:22},{left:137,right:159,top:377,bottom:399,width:22,height:22}];
    f.canvas.$refs.edgeControls={querySelectorAll:()=>bounds.map(rect=>({dataset:{workflowEdgeSource:'action:test'},style:{visibility:'visible'},getBoundingClientRect:()=>rect}))};
    f.canvas.fitView();
    for (const rect of bounds) {
        assert.ok(rect.top*f.canvas.scale+f.canvas.translateY>=48-0.000001);
        assert.ok(rect.bottom*f.canvas.scale+f.canvas.translateY<=552+0.000001);
    }
    assert.deepEqual(Object.keys(f.canvas.positions),[]);
});

test('an occupied upper or lower insertion keeps the new node on its branch side', () => {
    for (const [port,direction] of [['yes',-1],['no',1]]) {
        const f=fixture(),before={branch:{x:200,y:400},occupied:{x:450,y:Math.round((400+direction*240*.8)/10)*10}};
        f.canvas.pendingInsertion={sourceId:'branch',targetId:null,nodes:before,edges:[]};
        let placed;f.canvas.restoreCardPositions=points=>{placed=points;};
        f.canvas.placeInsertedNode({sourceId:'branch',targetId:null,sourcePort:port,nodeId:'new'});
        assert.ok((placed.new.y-before.occupied.y)*direction>0);
        assert.deepEqual(placed.occupied,before.occupied);
    }
});

test('fit view includes connected vertical detours outside the node bounds', () => {
    const f=fixture(),card={getBoundingClientRect:()=>({left:100,top:100,right:196,bottom:196})};
    f.canvas.nodeElements=()=>[{querySelector:selector=>selector==='[data-workflow-node-card]'?card:null}];
    const path={left:148,top:-200,right:430,bottom:148};
    f.canvas.$refs.stage={getBoundingClientRect:()=>({left:0,top:0}),querySelector:()=>null};
    f.canvas.$refs.edgeLayer={querySelectorAll:selector=>selector==='.workflow-node-edge'?[{getBoundingClientRect:()=>path}]:[]};
    f.canvas.$refs.viewport.clientWidth=800;f.canvas.$refs.viewport.clientHeight=600;
    f.canvas.fitView();
    assert.ok(path.top*f.canvas.scale+f.canvas.translateY>=48-0.000001);
    assert.ok(path.right*f.canvas.scale+f.canvas.translateX<=752+0.000001);
    assert.deepEqual(Object.keys(f.canvas.positions),[]);
});


test('nearly level ports draw a single straight segment without moving endpoints or saved routes', () => {
    const {canvas}=fixture();
    for (const delta of [-4,-1,-0.25,0.25,1,4]) {
        const start={x:100,y:60},end={x:700,y:60+delta};
        const baseline=60+delta/2;
        for (const saved of [null,{kind:'curve',x:350,y:baseline+.1}]) {
            const before=JSON.stringify({start,end,saved});
            const points=assertOrthogonal(canvas.connectionRoute(start,end,false,saved).d,0);
            assert.equal(points.length,2);
            assert.equal(points[0].y,baseline);
            assert.equal(points[1].y,baseline);
            assert.ok(Math.abs(points[0].y-start.y)<=2);
            assert.ok(Math.abs(points[1].y-end.y)<=2);
            assert.equal(JSON.stringify({start,end,saved}),before);
        }
    }
    for (const delta of [-20,20]) {
        const points=assertOrthogonal(canvas.connectionRoute({x:100,y:60},{x:700,y:60+delta},false).d,2);
        assert.equal(points.length,4,'A deliberate node offset keeps its right-angle bends');
    }
});

test('a vertical output meets a nearby target lane without a tiny input step', () => {
    const {canvas}=fixture();
    for (const direction of [-1,1]) {
        const start={x:100,y:200},end={x:700,y:200+direction*100};
        const saved={kind:'curve',x:650,y:end.y+direction};
        const points=assertOrthogonal(canvas.connectionRoute(start,end,false,saved,{x:0,y:direction}).d,1);
        assert.deepEqual(points,[start,{x:start.x,y:end.y},end]);
        assert.equal(saved.y,end.y+direction);
    }
});

test('screen-space edge painting stays stable through repeated zoom and pan without changing graph data', () => {
    const {canvas,window}=fixture();
    canvas.positions={a:{x:13.25,y:47.75}};canvas.edgeRoutes={kept:{kind:'curve',x:301,y:207}};
    const before=JSON.stringify({positions:canvas.positions,routes:canvas.edgeRoutes});
    let scale=1,left=57.25,top=89.375;
    const layer={style:{},getScreenCTM(){
        const previous=this.workflowPixelOffset||{x:0,y:0,scale:1};
        return {a:scale/previous.scale,b:0,c:0,d:scale/previous.scale,e:left+scale*previous.x,f:top+scale*previous.y};
    }};
    for(const ratio of [1,2])for(const zoom of [1,.75,1.33,2,.01]) {
        window.devicePixelRatio=ratio;scale=zoom;left-=13.375;top+=7.125;
        for(let index=0;index<20;index++) {
            const matrix=canvas.alignEdgeLayerPixels(layer);
            assert.ok(Math.abs(matrix.a-1)<1e-9);assert.ok(Math.abs(matrix.d-1)<1e-9);
            assert.ok(Math.abs(matrix.worldScale-zoom)<1e-9);
            assert.ok(Math.abs(matrix.e*ratio-Math.round(matrix.e*ratio))<1e-9);
            assert.ok(Math.abs(matrix.f*ratio-Math.round(matrix.f*ratio))<1e-9);
            assert.ok(Math.abs(layer.workflowPixelOffset.x*zoom)<=.5/ratio+1e-9);
            assert.ok(Math.abs(layer.workflowPixelOffset.y*zoom)<=.5/ratio+1e-9);
        }
    }
    assert.equal(JSON.stringify({positions:canvas.positions,routes:canvas.edgeRoutes}),before);
});

test('ordinary and selected lines land on physical pixel centres at fractional zoom and display densities', () => {
    const {canvas,window}=fixture();
    const raw='M 13.25 47.75 L 120.625 47.75 L 120.625 201.375 L 420.5 201.375';
    const original=assertOrthogonal(raw,2);
    for(const ratio of [1,1.25,2,3])for(const scale of [.25,.75,1,1.33,2])for(const width of [1,2]) {
        window.devicePixelRatio=ratio;
        const matrix={a:1,b:0,c:0,d:1,e:12,f:19,worldScale:scale};
        const points=assertOrthogonal(canvas.pixelAlignedEdgePath(raw,matrix,width),2);
        const phase=Math.round(width*ratio)%2?.5:0;
        points.forEach((point,index)=>{
            for(const [axis,offset] of [['x',12],['y',19]]) {
                const physical=(point[axis]+offset)*ratio-phase;
                assert.ok(Math.abs(physical-Math.round(physical))<1e-8);
                assert.ok(Math.abs(point[axis]-original[index][axis]*scale)<=.5/ratio+1e-8);
            }
        });
    }
});

test('screen-space rendering keeps edge drag data in world units and scales the grip and hit area', () => {
    const scale=.75;
    const f=routingFixture([{id:'action:a',x:100,y:100},{id:'action:b',x:500,y:100}],
        [{source:'action:a',target:'action:b'}],scale);
    f.window.devicePixelRatio=2;
    const layer=f.canvas.$refs.edgeLayer;
    layer.getScreenCTM=function(){
        const previous=this.workflowPixelOffset||{x:0,y:0,scale:1};
        return {a:scale/previous.scale,b:0,c:0,d:scale/previous.scale,e:57+scale*previous.x,f:89+scale*previous.y};
    };
    f.canvas.selectedEdge={sourceId:'action:a',sourcePort:'output',targetId:'action:b'};
    f.draw();
    const grip=f.elements().find(el=>el.attributes.class==='workflow-edge-route-handle');
    const hit=f.elements().find(el=>el.attributes.class==='workflow-node-edge-hit');
    assert.equal(grip.attributes.cx,348*scale);assert.equal(grip.attributes.cy,148*scale);
    assert.equal(grip.attributes.r,5);assert.equal(hit.attributes.style,'stroke-width:12px');
    let dragged;
    f.canvas.startEdgeRouteDrag=(edge,route,event,part)=>{dragged={edge,route,part};};
    grip.listeners.pointerdown(f.event(200,200));
    assert.deepEqual(JSON.parse(JSON.stringify(dragged.route)),{kind:'curve',x:348,y:148});
    assert.equal(dragged.part,'lane');
});


test('new loop body replaces the right tile without moving existing nodes', () => {
    const f=fixture(),before={loop:{x:200,y:400},other:{x:900,y:100}};
    f.canvas.pendingInsertion={sourceId:'loop',targetId:null,nodes:before,edges:[]};
    let placed;f.canvas.restoreCardPositions=points=>{placed=points;};
    f.canvas.placeInsertedNode({sourceId:'loop',targetId:null,sourcePort:'each',nodeId:'body'});
    assert.deepEqual(placed.loop,before.loop);assert.deepEqual(placed.other,before.other);
    assert.deepEqual(JSON.parse(JSON.stringify(placed.body)),{x:450,y:400});
});

test('loop returns enter from below while keeping the left input separate', () => {
    const {canvas}=fixture();
    for (const sourceVector of [{x:1,y:0},{x:0,y:-1},{x:0,y:1}]) {
        const start={x:600,y:148},end={x:148,y:196};
        const route=canvas.connectionRoute(start,end,true,null,sourceVector,{x:0,y:1});
        const points=assertOrthogonal(route.d,4);
        assert.deepEqual(points[0],start);assert.deepEqual(points.at(-1),end);
        assert.equal(points.at(-2).x,end.x);assert.ok(points.at(-2).y>end.y);
        assert.equal(route.handles.length,1);
    }
    const controls=[false,true].map(loopReturn=>({dataset:{workflowEdgeSource:loopReturn?'body':'trigger',workflowEdgeTarget:'loop',workflowEdgePort:'output',workflowLoopReturn:String(loopReturn)}}));
    canvas.$refs.edgeControls={querySelectorAll:()=>controls};canvas.$refs.stage={querySelector:()=>null};
    const reconnected=[];canvas.beginEdgeReconnect=edge=>reconnected.push(edge.sourceId);
    canvas.startIncomingConnection('loop',{button:0});
    canvas.startIncomingConnection('loop',{button:0},true);
    assert.deepEqual(reconnected,['trigger','body']);
});


test('the lower loop input keeps its connection tolerance when the graph is zoomed out', () => {
    const {canvas}=fixture();
    canvas.connecting={sourceId:'action:body',sourcePort:'output'};
    const ports=[{left:100,top:140,width:4,height:4},{left:145,top:190,width:4,height:4}]
        .map(box=>({getBoundingClientRect:()=>box}));
    canvas.nodeElements=()=>[{dataset:{workflowNodeId:'action:loop'},querySelectorAll:()=>ports}];
    assert.equal(canvas.connectionTargetAt({clientX:149,clientY:199}),'action:loop');
    assert.equal(canvas.connectionTargetAt({clientX:149,clientY:230}),null);
});

test('branches from different columns use one vertical spine before a shared input', () => {
    const boxes=[{id:'action:upper',x:100,y:100},{id:'action:lower',x:400,y:400},{id:'action:target',x:900,y:250}];
    const edges=[{source:'action:upper',target:'action:target'},{source:'action:lower',target:'action:target'}];
    for(const scale of [.25,.5,1,1.33,2])for(const ordered of [edges,[...edges].reverse()]) {
        const f=routingFixture(boxes,ordered,scale),paths=f.draw();
        const points=paths.map(path=>assertOrthogonal(path,2));
        points.forEach(path=>{
            assert.ok(Math.abs(path[1].x-698)<1e-6);
            assert.ok(Math.abs(path[2].x-698)<1e-6);
            assert.ok(Math.abs(path.at(-1).x-900)<1e-6);
            assert.ok(Math.abs(path.at(-1).y-298)<1e-6);
        });
        assert.deepEqual(Object.keys(f.canvas.edgeRoutes),[],'Drawing must not write saved routes');
        assert.deepEqual(Object.keys(f.canvas.positions),[],'Drawing must not move nodes');
    }
});

test('a short automatic branch to the email input turns once and preserves a manual detour', () => {
    const f = routingFixture([
        {id:'action:has-email',x:1081,y:642},
        {id:'action:find-email',x:1150,y:317},
    ], [{source:'action:has-email',port:'yes',target:'action:find-email'}]);
    assert.deepEqual(assertOrthogonal(f.draw()[0],1), [
        {x:1129,y:642}, {x:1129,y:365}, {x:1150,y:365},
    ]);
    const key = f.canvas.edgeRouteKey({sourceId:'action:has-email',sourcePort:'yes',targetId:'action:find-email'});
    const manual = {kind:'curve',x:1126,y:301};
    f.canvas.edgeRoutes[key] = manual;
    assert.deepEqual(assertOrthogonal(f.draw()[0],3), [
        {x:1129,y:642}, {x:1129,y:301}, {x:1126,y:301}, {x:1126,y:365}, {x:1150,y:365},
    ]);
    assert.deepEqual(f.canvas.edgeRoutes[key], manual);
});

test('short and long automatic branches share a single approach to the phone input', () => {
    const f = routingFixture([
        {id:'action:short',x:1572,y:315},
        {id:'action:long',x:1081,y:638},
        {id:'action:phone',x:1650,y:671},
    ], [{source:'action:short',port:'no',target:'action:phone'}, {source:'action:long',port:'no',target:'action:phone'}]);
    const [short, long] = f.draw().map(path => assertOrthogonal(path,3));
    const approach = [{x:1620,y:719}, {x:1650,y:719}];
    assert.deepEqual(short, [{x:1620,y:411}, ...approach]);
    assert.deepEqual(long.slice(-2), approach);
    assert.deepEqual(Object.keys(f.canvas.edgeRoutes), []);
    assert.deepEqual(Object.keys(f.canvas.positions), []);

    const key = f.canvas.edgeRouteKey({sourceId:'action:long',sourcePort:'no',targetId:'action:phone'});
    const manual = {kind:'curve',x:1626,y:798};
    f.canvas.edgeRoutes[key] = manual;
    const [other, pinned] = f.draw().map(path => assertOrthogonal(path,3));
    assert.deepEqual(other, short);
    assert.deepEqual(pinned.slice(-2), [{x:1626,y:719}, {x:1650,y:719}]);
    assert.deepEqual(f.canvas.edgeRoutes[key], manual);
});

test('vertical branch outputs share the final input spine and a nearly level branch stays flat', () => {
    for(const port of ['yes','no']) {
        const f=routingFixture([{id:'action:condition',x:100,y:200},{id:'action:lower',x:500,y:500},{id:'action:target',x:950,y:200}],
            [{source:'action:condition',port,target:'action:target'},{source:'action:lower',target:'action:target'}]);
        f.draw().map(path=>assertOrthogonal(path,3)).forEach(points=>{
            assert.equal(points.at(-3).x,773);assert.equal(points.at(-2).x,773);
            assert.deepEqual(points.at(-1),{x:950,y:248});
        });
    }
    const f=routingFixture([{id:'action:upper',x:100,y:100},{id:'action:level',x:400,y:251},{id:'action:target',x:900,y:250}],
        [{source:'action:upper',target:'action:target'},{source:'action:level',target:'action:target'}]);
    const [upper,level]=f.draw().map(path=>assertOrthogonal(path,2));
    assert.equal(upper.at(-1).y,298);assert.equal(level.length,2);assert.equal(level[0].y,298);assert.equal(level[1].y,298);
});

test('shared input columns preserve saved route data while resolving older mismatched spines', () => {
    const f=routingFixture([{id:'action:a',x:100,y:100},{id:'action:b',x:400,y:400},{id:'action:c',x:900,y:250}],
        [{source:'action:a',target:'action:c'},{source:'action:b',target:'action:c'}]);
    f.canvas.edgeRoutes={
        [f.canvas.edgeRouteKey({sourceId:'action:a',sourcePort:'output',targetId:'action:c'})]:{kind:'curve',x:650,y:223},
        [f.canvas.edgeRouteKey({sourceId:'action:b',sourcePort:'output',targetId:'action:c'})]:{kind:'curve',x:730,y:373},
    };
    const before=JSON.stringify(f.canvas.edgeRoutes);
    f.draw().forEach(path=>assert.equal(assertOrthogonal(path,2)[1].x,730));
    assert.equal(JSON.stringify(f.canvas.edgeRoutes),before);
});

test('dragging or cancelling a shared spine updates the whole merge and preserves unrelated routes', () => {
    for(const scale of [.5,1,2]) {
        const boxes=[{id:'action:a',x:100,y:100},{id:'action:b',x:400,y:400},{id:'action:c',x:900,y:250}];
        const edges=[{source:'action:a',target:'action:c'},{source:'action:b',target:'action:c'}];
        const f=routingFixture(boxes,edges,scale),edge={sourceId:'action:a',sourcePort:'output',targetId:'action:c'};
        f.canvas.edgeRoutes.unrelated={kind:'curve',x:12,y:34};f.draw();
        const key=f.canvas.edgeRouteKey(edge),group=f.canvas.inputRouteGroups.get(key);
        const route=group.routes.find(item=>item.key===key).route;
        f.canvas.startEdgeRouteDrag(edge,route,f.event(100,100),'spine');
        f.canvas.moveCanvas(f.event(140,100));
        f.canvas.stopCanvasInteraction(f.event(140,100));
        const keys=edges.map(item=>f.canvas.edgeRouteKey({sourceId:item.source,sourcePort:'output',targetId:item.target}));
        assert.equal(f.canvas.edgeRoutes[keys[0]].x,f.canvas.edgeRoutes[keys[1]].x);
        const x=f.canvas.edgeRoutes[key].x;
        f.draw().forEach(path=>assert.ok(Math.abs(assertOrthogonal(path,2)[1].x-x)<1e-6));
        const saved=JSON.stringify(f.canvas.edgeRoutes),saves=f.saves();
        f.canvas.startEdgeRouteDrag(edge,f.canvas.edgeRoutes[key],f.event(100,100),'spine');
        f.canvas.moveCanvas(f.event(80,100));
        f.canvas.stopCanvasInteraction({...f.event(80,100),type:'pointercancel'});
        assert.equal(JSON.stringify(f.canvas.edgeRoutes),saved);assert.equal(f.saves(),saves);
        assert.deepEqual(f.canvas.edgeRoutes.unrelated,{kind:'curve',x:12,y:34});
        assert.deepEqual(Object.keys(f.canvas.positions),[]);
    }
});


test('old vertical branch lanes cannot collapse the common input spine after direction clamping', () => {
    const {canvas}=fixture();
    for(const direction of [-1,1]) {
        const start={x:148,y:200},end={x:950,y:200+direction*24};
        const route=canvas.connectionRoute(start,end,false,{kind:'curve',x:773,y:direction>0?-1000:1000},{x:0,y:direction},{x:-1,y:0},0,773);
        const points=assertOrthogonal(route.d,3);
        assert.equal(points.at(-3).x,773);assert.equal(points.at(-2).x,773);
        assert.ok(Math.abs(points.at(-3).y-points.at(-2).y)>=24);
        assert.deepEqual(points.at(-1),end);
    }
});
