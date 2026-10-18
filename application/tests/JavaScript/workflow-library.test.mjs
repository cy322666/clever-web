import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';

const source = readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflows/node-library.blade.php', import.meta.url), 'utf8');
const catalog = readFileSync(new URL('../../resources/views/filament/workflow-builder/workflow-node-catalog.blade.php', import.meta.url), 'utf8');
function fixture(inspector = null) {
    const window={innerWidth:1440,innerHeight:900,addEventListener(){},dispatchEvent(){}};
    runInNewContext(readFileSync(new URL('../../resources/js/app.js',import.meta.url),'utf8'), {window,document:{addEventListener(){},querySelector:()=>inspector && {getBoundingClientRect:()=>inspector}},CustomEvent:class{}});
    const state=window.workflowNodeLibrary(), changes=[];
    state.$nextTick=fn=>fn();state.$refs={panel:{contains:()=>false}};state.$wire={$set:(...args)=>changes.push(args)};
    return {state,changes};
}
test('catalog browsing is entirely client side and insertion state is reset only on close', () => {
    assert.doesNotMatch(source,/workflow-node-library__rail/);
    assert.doesNotMatch(source,/wire:click="(?:switchActionPalette|beginTriggerAdd|cancelActionInsertion)/);
    const {state,changes}=fixture();
    state.openFor();assert.equal(state.mode,'all');
    for (const mode of ['trigger', 'action', 'variables']) { state.openFor({mode}); assert.equal(state.mode, mode); assert.equal(state.open,true); }
    state.openFor({mode:'query'}); assert.equal(state.mode,'action'); assert.equal(state.view,'amocrm');
    state.openFor({mode:'service'}); assert.equal(state.mode,'action'); assert.equal(state.view,'service');
    assert.deepEqual(changes, []);
    state.openFor({mode:'trigger', replace:true}); assert.equal(state.replaceTrigger,true);
    state.openFor({mode:'trigger'}); assert.equal(state.replaceTrigger,false);
    state.openFor({anchor:{x:1400,y:880}});
    assert.equal(state.floatingStyle().left,'898px');assert.equal(state.floatingStyle().top,'308px');
    assert.equal('display' in state.floatingStyle(),false);
    state.closeLibrary(); assert.equal(state.open,false);
    assert.equal(state.floatingStyle().position,'fixed');
    assert.deepEqual(changes, ['insertConnection','insertActionPath','insertActionIndex','targetPath'].map(key=>[key,null,false]));
    assert.match(catalog, /workflowEditorCall\(\$wire, 'selectTriggerType', @js\(\$trigger\['type'\]\), !replaceTrigger\)/);
});

test('references open beside the visible inspector and stay on screen on narrow viewports', () => {
    const {state}=fixture({left:1000,top:100});
    state.openFor({mode:'variables',anchor:{x:1400,y:880}});
    assert.equal(state.floatingStyle().left,'668px');
    assert.equal(state.floatingStyle().top,'112px');
    state.viewport={width:375,height:400};
    assert.equal(state.floatingStyle().left,'43px');
    assert.equal(state.floatingStyle().top,'12px');
    assert.match(source,/x-bind:data-workflow-reference-panel/);
    assert.match(source,/dismissLibrary\(\$event\)/);
});

test('reference browsing and pasting coexist without requests; ordinary catalog still dismisses', () => {
    const {state,changes}=fixture();state.openFor({mode:'variables'});
    for (const selector of ['.workflow-inspector','[data-workflow-reference-toggle]']) {
        state.dismissLibrary({target:{closest:selectors=>selectors.includes(selector) ? {} : null}});
        assert.equal(state.open,true);
    }
    state.$refs.panel.contains=()=>true;
    state.dismissLibrary({target:{}});assert.equal(state.open,true);
    assert.deepEqual(changes,[]);
    state.$refs.panel.contains=()=>false;
    state.dismissLibrary({target:{}});assert.equal(state.open,false);
    state.openFor({mode:'action'});
    state.dismissLibrary({target:{closest:()=>({})}});assert.equal(state.open,false);
});

test('the app-first catalog switches categories locally and keeps triggers out of branch mode', () => {
    const {state,changes}=fixture();state.openFor({anchor:{x:300,y:200}});
    for(const category of ['logic','amocrm','service','trigger:system']) {
        state.selectCategory(category);
        assert.equal(state.view,category);
        assert.equal(state.mode,'all');
        assert.equal(state.query,'');
    }
    state.selectCategory('amocrm');state.selectEntity('lead');assert.equal(state.view,'amocrm:lead');
    state.selectCategory('amocrm');assert.equal(state.view,'amocrm');
    state.openFor({mode:'action'});
    assert.equal(state.view,'logic');
    assert.match(catalog,/x-show="mode === 'trigger' \|\| mode === 'all'"/);
    assert.match(catalog,/x-show="\(mode === 'trigger' \|\| mode === 'all'\) &&/);
    assert.match(catalog,/x-show="mode !== 'trigger'"/);
    assert.deepEqual(changes,[]);assert.equal(state.anchor,null);
    assert.doesNotMatch(catalog, /'variables' => \[/, 'References are separate from the node menu');
    assert.match(source, /workflow-masks-open.window/);
});

test('global search covers actions, queries, services and triggers without losing insertion state', () => {
    const {state,changes}=fixture();state.openFor();state.query='получить КОНТАКТ';
    assert.equal(state.matches('amoCRM Контакты получить контакт'),true);
    assert.equal(state.matches('Создать контакт'),false);
    assert.match(catalog,/view === @js\('amocrm:'\.\$key\) : matchesAny\(@js\(\$entity\['search_texts'\]\)\)/);
    assert.match(catalog,/x-on:click="selectEntity\(@js\(\$key\)\)"/);
    assert.doesNotMatch(catalog,/<span>Запросы<\/span>/);
    assert.match(catalog,/view === 'service' : matches\('telegram телеграм отправить сообщение'\)/);
    assert.match(catalog,/query === '' \|\| matches\(@js\(\$trigger\['search_text'\]\)\)/);
    assert.match(catalog,/query === '' \|\| matches\(@js\(\$action\['search_text'\]\)\)/);
    assert.deepEqual(changes,[]);
});

test('floating catalog stays within narrow viewports and replacement stays in triggers', () => {
    const {state}=fixture();state.openFor({mode:'trigger',replace:true,anchor:{x:1400,y:880}});
    state.selectCategory('trigger:lead');assert.equal(state.mode,'trigger');assert.equal(state.replaceTrigger,true);
    state.viewport={width:375,height:400};
    assert.equal(state.floatingStyle().left,'12px');assert.equal(state.floatingStyle().top,'12px');
    assert.equal('display' in state.floatingStyle(),false);
});

test('saved button has an explicit dark palette and branch ports use whole snap steps', () => {
    const css = readFileSync(new URL('../../resources/css/filament-workflows.css', import.meta.url), 'utf8');
    assert.match(css, /\.dark[^\n]+\.workflow-save-button--clean\s*\{[^}]*background: var\(--workflow-dark-panel/s);
    for (const port of ['yes', 'done']) assert.ok(css.includes(`.workflow-node-port--output-${port} {\n    top: calc(50% - 20px);`));
    for (const port of ['no', 'each']) assert.ok(css.includes(`.workflow-node-port--output-${port} {\n    top: calc(50% + 20px);`));
});

test('empty start, plus controls, node hover and toolbar have distinct visual feedback', () => {
    const css = readFileSync(new URL('../../resources/css/filament-workflows.css', import.meta.url), 'utf8');
    const builder = readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflow-builder.blade.php', import.meta.url), 'utf8');
    assert.match(builder,/data-workflow-empty-start/);
    assert.match(builder,/icon="heroicon-o-plus"/);
    assert.match(builder, /data-workflow-start-add/);
    assert.match(builder, /Выбрать событие/);
    assert.match(builder, /@if\(\$workflowStarts === \[\]\)\s*<div class="workflow-node-shell workflow-node-shell--trigger" data-workflow-start-add/s);
    assert.match(builder, /class="workflow-canvas-fit workflow-canvas-add-trigger"[^>]*x-show="Boolean\(\$wire\.trigger\)"/);
    assert.match(builder, /aria-label="Добавить способ запуска" title="Добавить способ запуска"/);
    assert.doesNotMatch(builder, /<span>Добавить запуск<\/span>/);
    assert.match(builder, /captureTriggerAdd\(\); workflowEditorCall\(\$wire, 'beginTriggerAdd'\)/);
    assert.match(builder, /workflow-canvas-before-update\.window=/);
    assert.match(builder, /workflow-canvas-after-update\.window=/);
    assert.match(css,/\.workflow-node-empty\[data-workflow-empty-start\]\s*\{[^}]*border: 2px dashed[^}]*pointer-events: auto/s);
    assert.doesNotMatch(css,/\.workflow-node-starts \[data-workflow-start-add\] \{ position: absolute/);
    const draft = readFileSync(new URL('../../resources/js/workflow-editor-draft.mjs', import.meta.url), 'utf8');
    assert.match(draft, /announce\('workflow-canvas-before-update'/);
    assert.match(draft, /announce\('workflow-canvas-after-update'/);
    assert.match(draft, /const starts = main\.querySelector\('\.workflow-node-starts'\);/);
    assert.match(draft, /if \(wire\.trigger\) \{\s*if \(addSlot\) window\.Alpine\.mutateDom/s);
    assert.match(draft, /if \(addSlot !== starts\.lastElementChild\)/);
    assert.match(catalog, /<span>amoCRM<\/span><small>/);
    assert.match(catalog, /<span>Сервисы<\/span><small>1<\/small>/);
    assert.doesNotMatch(catalog, /workflow-node-library__item-add/);
    assert.match(css,/\.workflow-node-edge-add:not\(\.workflow-loop-replace\):not\(\[data-workflow-edge-delete\]\):is\(:hover, :focus-visible\)\s*\{[^}]*scale\(1\.3\)/s);
    assert.match(css,/\.workflow-canvas-controls \.workflow-canvas-fit \{[^}]*width: 34px; height: 34px/s);
    assert.match(css,/\.workflow-canvas-controls \.workflow-canvas-add-trigger \{[^}]*flex: 0 0 34px/s);
    assert.match(css,/\.workflow-node-card\[data-node-family\]:not\(\.workflow-node-card--disabled\):is\(:hover, :focus-visible\)\s*\{[^}]*outline-color/s);
    assert.match(css,/\.workflow-workbench--page \.workflow-workbench__toolbar,[^{]*\{\s*min-height: 2\.75rem/s);
});
