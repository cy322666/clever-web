import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';

function fixture(name = 'Исходный') {
    const window = {addEventListener() {}};
    runInNewContext(readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8'), {
        window, document: {addEventListener() {}},
    });
    const writes = [];
    const state = window.workflowInlineWorkflowName();
    state.$wire = {data: {name, group_name: 'Папка'}, $set(path, value, live) {
        assert.equal(path, 'data.name');
        assert.equal(live, false, 'Renaming must never request the server or autosave');
        writes.push(value);
        this.data.name = value;
    }, renameWorkflow() {assert.fail('Do not send rename requests');}};
    let focuses = 0, selections = 0;
    state.$refs = {name: {focus() {focuses++;}, select() {selections++;}}, title: {focus() {focuses++;}}};
    state.$nextTick = callback => callback();
    return {state, writes, focuses: () => focuses, selections: () => selections};
}

test('typing updates the browser draft immediately; confirming trims without a server request', () => {
    const {state, writes, selections} = fixture();
    state.startRename();
    assert.equal(state.nameDraft, 'Исходный');
    assert.equal(selections(), 1);
    state.updateRename('  Новое название  ');
    assert.equal(state.$wire.data.name, '  Новое название  ', 'An immediate Save must see the latest input');
    assert.equal(state.commitRename(), true);
    assert.equal(state.editing, false);
    assert.equal(state.workflowName, 'Новое название');
    assert.equal(state.$wire.data.group_name, 'Папка');
    assert.deepEqual(writes, ['  Новое название  ', 'Новое название']);
    state.commitRename();
    assert.equal(writes.length, 2, 'Outside pointerdown followed by focusout must not commit twice');
});

test('Escape restores the name at the start of this edit, not an older saved title', () => {
    const {state} = fixture();
    state.startRename(); state.updateRename('Первое'); state.commitRename();
    state.startRename(); state.updateRename('Отменить'); state.cancelRename();
    assert.equal(state.workflowName, 'Первое');
    assert.equal(state.nameDraft, 'Первое');
    assert.equal(state.editing, false);
    assert.equal(state.renameError, '');
    state.commitRename();
    assert.equal(state.workflowName, 'Первое', 'A blur after Escape must not restore the cancelled draft');
});

test('empty or too long names keep the field open with an accessible error and can be corrected', () => {
    for (const value of ['   ', 'я'.repeat(256)]) {
        const {state} = fixture();
        state.startRename(); state.updateRename(value);
        assert.equal(state.commitRename(), false);
        assert.equal(state.editing, true);
        assert.match(state.renameError, /от 1 до 255/);
        state.updateRename('Исправлено');
        assert.equal(state.renameError, '');
        assert.equal(state.commitRename(), true);
        assert.equal(state.workflowName, 'Исправлено');
    }
});

test('Save confirms the current field and prevents submission only for invalid names', () => {
    const {state, focuses} = fixture();
    let prevented = 0, stopped = 0;
    const event = {preventDefault() {prevented++;}, stopImmediatePropagation() {stopped++;}};
    state.startRename(); state.updateRename('  Готово  ');
    state.finishBeforeSave(event);
    assert.equal(prevented, 0);
    assert.equal(state.$wire.data.name, 'Готово');
    state.startRename(); state.updateRename(' ');
    state.finishBeforeSave(event);
    assert.equal(prevented, 1);
    assert.equal(stopped, 1);
    assert.equal(state.editing, true);
    assert.ok(focuses() >= 3);
});

test('unchanged names do not issue writes and unrelated server acknowledgements keep the current draft', () => {
    const {state, writes} = fixture();
    state.startRename(); state.commitRename();
    assert.equal(writes.length, 0);
    state.startRename(); state.updateRename('Черновик');
    state.$wire.savedWorkflowEditorState = {name: 'Исходный'};
    assert.equal(state.nameDraft, 'Черновик');
    assert.equal(state.workflowName, 'Черновик');
});

test('an unnamed new workflow can cancel without inventing a stored title', () => {
    const {state} = fixture('');
    state.startRename();
    assert.equal(state.nameDraft, 'Новый процесс');
    state.updateRename('Отменить'); state.cancelRename();
    assert.equal(state.$wire.data.name, '');
    assert.equal(state.workflowName, 'Новый процесс');
});

test('outside clicks commit before the canvas prevents blur, and submit flushes before Livewire', () => {
    const view = readFileSync(new URL('../../resources/views/vendor/filament-workflows/components/workflow-builder.blade.php', import.meta.url), 'utf8');
    const title = view.slice(view.indexOf('<div class="workflow-identity-inline"'), view.indexOf('<details class="workflow-identity-popover"'));
    assert.match(title, /x-data="workflowInlineWorkflowName\(\)"/);
    assert.match(title, /x-on:pointerdown\.outside\.capture="commitRename\(\)"/);
    assert.match(title, /x-on:submit\.window\.capture="if \(\$event.target.contains\(\$el\)\) finishBeforeSave\(\$event\)"/);
    assert.match(title, /x-model="nameDraft"/);
    assert.match(title, /x-on:input="updateRename\(\$event.target.value\)"/);
    assert.match(title, /!\$event.isComposing/);
    assert.match(title, /x-on:keydown.escape.prevent.stop="cancelRename\(\)"/);
    assert.match(title, /role="alert"/);
    assert.doesNotMatch(title, /workflowEditorCall|\$wire\.renameWorkflow|value="\{\{ \$workflowTitle/);
});
