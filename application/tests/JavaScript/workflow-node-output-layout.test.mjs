import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const css = postcss.parse(readFileSync(new URL('../../resources/css/filament-workflows.css', import.meta.url), 'utf8'));

function declarations(selector) {
    const result = {};
    css.walkRules(rule => {
        if (!rule.selectors.includes(selector)) return;
        rule.walkDecls(declaration => { result[declaration.prop] = declaration.value; });
    });
    return result;
}

test('node output grows through the full pane without shrinking its heading', () => {
    const pane = declarations('.workflow-node-output');
    assert.equal(pane.display, 'flex');
    assert.equal(pane['flex-direction'], 'column');
    assert.equal(pane.height, '100%');
    assert.equal(pane['min-height'], '0');
    assert.equal(declarations('.workflow-node-output > :not(.workflow-json-tree)').flex, '0 0 auto');

    const tree = declarations('.workflow-node-output .workflow-json-tree');
    assert.equal(tree.display, 'flex');
    assert.equal(tree['flex-direction'], 'column');
    assert.equal(tree.flex, '1 1 0');
    assert.equal(tree['min-height'], '0');
    assert.equal(declarations('.workflow-node-output .workflow-json-tree__header').flex, '0 0 auto');
});

test('only the node result replaces the shared height cap with remaining space', () => {
    const shared = declarations('.workflow-json-tree__viewport');
    assert.equal(shared['max-height'], '32vh');
    assert.equal(shared.overflow, 'auto');

    const output = declarations('.workflow-node-output .workflow-json-tree__viewport');
    assert.equal(output['max-height'], 'none');
    assert.equal(output['min-height'], '0');
    assert.equal(output.flex, '1 1 0');
});

test('result text and fold controls use compact matching line heights', () => {
    const text = declarations('.workflow-node-output .workflow-json-tree .workflow-input-json');
    assert.equal(text['font-size'], '11px');
    assert.equal(text['line-height'], '18px');
    assert.equal(declarations('.workflow-node-output .workflow-json-tree .workflow-input-json__line')['min-height'], '18px');
    assert.equal(declarations('.workflow-node-output .workflow-json-tree__toggle').height, '18px');
    assert.equal(declarations('.workflow-node-output .workflow-json-tree .workflow-input-json .workflow-input-json__fold').height, '18px');
});
