import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';
import test from 'node:test';

test('click copies the full expression, not the name or field ID, including duplicate field names', async () => {
    const writes = [], timers = [];
    const window = {addEventListener() {}, setTimeout(callback) { timers.push(callback); }};
    runInNewContext(readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8'), {
        window, document: {addEventListener() {}},
        navigator: {clipboard: {async writeText(value) { writes.push(value); }}},
    });
    const browser = window.workflowVariableBrowser({'Сделка': [
        {value:'{{lead.cf(1781099)}}', label:'Телефон', options:[]},
        {value:'{{lead.cf(1781100)}}', label:'Телефон', options:[]},
    ]});
    browser.type = 'Сделка';
    for (const item of browser.visibleItems) await browser.copy(item.value);
    assert.deepEqual(writes, ['{{lead.cf(1781099)}}', '{{lead.cf(1781100)}}']);
    assert.equal(browser.copied, '{{lead.cf(1781100)}}');
    timers[0]();
    assert.equal(browser.copied, '{{lead.cf(1781100)}}');
    timers[1]();
    assert.equal(browser.copied, '');
});
