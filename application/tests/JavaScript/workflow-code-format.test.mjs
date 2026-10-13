import test from 'node:test';
import assert from 'node:assert/strict';
import {formatJavascript, formatEditor} from '../../resources/js/workflow-code-format.mjs';

function editorFixture(source) {
    return {
        source, writes: 0, focused: false,
        get state() { return {doc: {toString: () => this.source, length: this.source.length}}; },
        dispatch({changes}) { this.source = changes.insert; this.writes++; },
        focus() { this.focused = true; },
    };
}
test('formats function-body code, optional chaining and workflow data without execution', async () => {
    globalThis.__workflowFormatExecuted = false;
    const formatted = await formatJavascript('globalThis.__workflowFormatExecuted=true;const x=$node["read"]?.json;if(x){return {id:x.id}}return $json;');
    assert.match(formatted, /if \(x\) \{\n  return \{ id: x.id \};\n\}/);
    assert.equal(globalThis.__workflowFormatExecuted, false);
    assert.equal(await formatJavascript(formatted), formatted);
    delete globalThis.__workflowFormatExecuted;
});
test('does not alter expression strings or regular expressions', async () => {
    const formatted = await formatJavascript('const value="{{ $vars.id }}";return value.replace(/\\D/g,"");');
    assert.ok(formatted.includes('{{ $vars.id }}'));
    assert.ok(formatted.includes('/\\D/g'));
});
test('updates through one undoable CodeMirror transaction', async () => {
    const editor = editorFixture('return {x:1}');
    assert.equal(await formatEditor(editor), true);
    assert.equal(editor.source, 'return { x: 1 };\n');
    assert.equal(editor.writes, 1);
    assert.equal(editor.focused, true);
});
test('syntax error preserves original document', async () => {
    const editor = editorFixture('return {broken:;');
    await assert.rejects(formatEditor(editor), SyntaxError);
    assert.equal(editor.source, 'return {broken:;');
    assert.equal(editor.writes, 0);
});
test('does not overwrite typing or another node after lazy load', async () => {
    for (const kind of ['edited', 'closed', 'destroyed']) {
        const editor = editorFixture('return 1');
        const formatter = async () => {
            if (kind === 'edited') editor.source = 'return 2';
            if (kind === 'destroyed') editor.destroyed = true;
            return 'return 1;\n';
        };
        assert.equal(await formatEditor(editor, () => kind !== 'closed', formatter), false);
        assert.equal(editor.writes, 0);
    }
});
