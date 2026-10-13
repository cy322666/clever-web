import { format } from 'prettier/standalone';
import babel from 'prettier/plugins/babel';
import estree from 'prettier/plugins/estree';

// Parse and print only. Never execute workflow code or send it over the network.
export async function formatJavascript(source) {
    return format(source, {
        parser: 'babel', plugins: [babel, estree], tabWidth: 2,
        printWidth: 80, singleQuote: true, trailingComma: 'all', endOfLine: 'lf',
    });
}

export async function formatEditor(editor, isCurrent = () => true, formatter = formatJavascript) {
    const source = editor.state.doc.toString();
    const formatted = await formatter(source);
    // A node can be closed, replaced or edited while the lazy formatter loads.
    if (!isCurrent() || editor.destroyed || editor.state.doc.toString() !== source) return false;
    if (formatted !== source) {
        editor.dispatch({ changes: { from: 0, to: editor.state.doc.length, insert: formatted } });
    }
    editor.focus();
    return true;
}
