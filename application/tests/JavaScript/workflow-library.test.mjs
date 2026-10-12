import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';

const source = readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflows/node-library.blade.php', import.meta.url), 'utf8');
test('catalog browsing is entirely client side and insertion state is reset only on close', () => {
    const rail = source.split('<div class="workflow-node-library__rail">')[1].split('<div class="workflow-node-library__panel"')[0];
    assert.doesNotMatch(rail, /wire:click="(?:switchActionPalette|beginTriggerAdd|cancelActionInsertion)/);
    const changes = [], state = runInNewContext('('+source.split('x-data="')[1].split('"\n    x-on:')[0].replace(/mode: @js\([^\n]+\),/, "mode: 'action',")+')');
    state.$nextTick = fn => fn(); state.$refs = {}; state.$wire = {$set: (...args)=>changes.push(args)};
    for (const mode of ['trigger', 'action', 'query', 'service', 'variables']) { state.openFor({mode}); assert.equal(state.mode, mode); assert.equal(state.open,true); }
    assert.deepEqual(changes, []);
    state.openFor({mode:'trigger', replace:true}); assert.equal(state.replaceTrigger,true);
    state.openFor({mode:'trigger'}); assert.equal(state.replaceTrigger,false);
    state.closeLibrary(); assert.equal(state.open,false);
    assert.deepEqual(changes, ['insertConnection','insertActionPath','insertActionIndex','targetPath'].map(key=>[key,null,false]));
    assert.match(source, /selectTriggerType\(@js\(\$trigger\['type'\]\), !replaceTrigger\)/);
});

test('saved button has an explicit dark palette and branch ports use whole snap steps', () => {
    const css = readFileSync(new URL('../../resources/css/filament-workflows.css', import.meta.url), 'utf8');
    assert.match(css, /\.dark[^\n]+\.workflow-save-button--clean\s*\{[^}]*background: var\(--workflow-dark-panel/s);
    for (const port of ['yes', 'done']) assert.ok(css.includes(`.workflow-node-port--output-${port} {\n    top: calc(50% - 20px);`));
    for (const port of ['no', 'each']) assert.ok(css.includes(`.workflow-node-port--output-${port} {\n    top: calc(50% + 20px);`));
});
