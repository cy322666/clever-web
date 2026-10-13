import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';

const source = readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflows/node-library.blade.php', import.meta.url), 'utf8');
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
    for (const mode of ['trigger', 'action', 'query', 'service', 'variables']) { state.openFor({mode}); assert.equal(state.mode, mode); assert.equal(state.open,true); }
    assert.deepEqual(changes, []);
    state.openFor({mode:'trigger', replace:true}); assert.equal(state.replaceTrigger,true);
    state.openFor({mode:'trigger'}); assert.equal(state.replaceTrigger,false);
    state.openFor({anchor:{x:1400,y:880}});
    assert.equal(state.floatingStyle().left,'1108px');assert.equal(state.floatingStyle().top,'418px');
    assert.equal('display' in state.floatingStyle(),false);
    state.closeLibrary(); assert.equal(state.open,false);
    assert.equal(state.floatingStyle().position,'fixed');
    assert.deepEqual(changes, ['insertConnection','insertActionPath','insertActionIndex','targetPath'].map(key=>[key,null,false]));
    assert.match(source, /selectTriggerType\(@js\(\$trigger\['type'\]\), !replaceTrigger\)/);
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

test('every category is available from the unified root, with nested back navigation', () => {
    const {state,changes}=fixture();state.openFor({anchor:{x:300,y:200}});
    for(const category of ['trigger','action','query','service']) {
        assert.ok(source.includes(`'${category}' => [`));
        state.resetView(category);state.view='subgroup';state.query='test';state.goBack();
        assert.equal(state.mode,category);assert.equal(state.view,'root');assert.equal(state.query,'');
        state.goBack();assert.equal(state.mode,'all');
    }
    state.resetView('action');state.view='variants';state.entity='lead';state.goBack();
    assert.equal(state.view,'amocrm');assert.equal(state.entity,null);
    state.goBack();assert.equal(state.view,'root');state.goBack();assert.equal(state.mode,'all');
    assert.deepEqual(changes,[]);assert.equal(state.anchor.x,300);
    assert.doesNotMatch(source, /'variables' => \[/, 'References are separate from the node menu');
    assert.match(source, /workflow-masks-open.window/);
});

test('global search covers actions, queries, services and triggers without losing insertion state', () => {
    const {state,changes}=fixture();state.openFor();state.query='получить КОНТАКТ';
    assert.equal(state.matches('amoCRM Контакты получить контакт'),true);
    assert.equal(state.matches('Создать контакт'),false);
    assert.match(source,/mode === 'query' \|\| \(mode === 'all' && query !== ''\)/);
    assert.match(source,/mode === 'service' \|\| \(mode === 'all' && query !== ''\)/);
    for(const category of ['trigger','action']) assert.ok(source.includes(`(mode === '${category}' || mode === 'all')`));
    assert.deepEqual(changes,[]);
});

test('floating catalog stays within narrow viewports and replacement stays in triggers', () => {
    const {state}=fixture();state.openFor({mode:'trigger',replace:true,anchor:{x:1400,y:880}});
    state.goBack();assert.equal(state.mode,'trigger');assert.equal(state.replaceTrigger,true);
    state.viewport={width:375,height:400};
    assert.equal(state.floatingStyle().left,'43px');assert.equal(state.floatingStyle().top,'12px');
    assert.equal('display' in state.floatingStyle(),false);
});

test('saved button has an explicit dark palette and branch ports use whole snap steps', () => {
    const css = readFileSync(new URL('../../resources/css/filament-workflows.css', import.meta.url), 'utf8');
    assert.match(css, /\.dark[^\n]+\.workflow-save-button--clean\s*\{[^}]*background: var\(--workflow-dark-panel/s);
    for (const port of ['yes', 'done']) assert.ok(css.includes(`.workflow-node-port--output-${port} {\n    top: calc(50% - 20px);`));
    for (const port of ['no', 'each']) assert.ok(css.includes(`.workflow-node-port--output-${port} {\n    top: calc(50% + 20px);`));
});
