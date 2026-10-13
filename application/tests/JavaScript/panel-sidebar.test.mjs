import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const stylesheet = postcss.parse(readFileSync(new URL('../../resources/css/filament-workflows.css', import.meta.url), 'utf8'));
const collapsedSidebar = '.fi-body-has-sidebar-collapsible-on-desktop .fi-main-sidebar:not(.fi-sidebar-open)';

function ruleFor(selector) {
    const rules = [];
    stylesheet.walkRules(selector, rule => rules.push(rule));
    assert.equal(rules.length, 1);
    assert.equal(rules[0].parent.type, 'atrule');
    assert.equal(rules[0].parent.name, 'media');
    assert.equal(rules[0].parent.params, '(min-width: 1024px)');
    return Object.fromEntries(rules[0].nodes.map(({prop, value}) => [prop, value]));
}

test('collapsed desktop navigation uses the configured width without changing the mobile drawer', () => {
    const rule = ruleFor(collapsedSidebar);
    assert.equal(rule.width, 'var(--collapsed-sidebar-width)');
    assert.equal(rule.flex, '0 0 var(--collapsed-sidebar-width)');
});

test('collapsed navigation keeps icons and click targets inside the compact rail', () => {
    const nav = ruleFor(`${collapsedSidebar} .fi-sidebar-nav`);
    assert.equal(nav['padding-inline'], '0.25rem');
    assert.equal(nav['scrollbar-gutter'], 'auto');
    assert.equal(ruleFor(`${collapsedSidebar} .fi-sidebar-nav-groups`)['margin-inline'], '0');
    assert.equal(ruleFor(`${collapsedSidebar} .fi-sidebar-item-btn`)['min-height'], '2.75rem');
});
