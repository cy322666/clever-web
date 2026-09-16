import { execFileSync } from 'node:child_process';
import { copyFileSync, mkdtempSync, readdirSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../public/amocrm/workflows');
const source = join(root, 'manual-buttons');
const staging = mkdtempSync(join(tmpdir(), 'workflow-widget-'));
function files(directory, prefix = '') {
    return readdirSync(directory, { withFileTypes: true }).flatMap(entry => {
        if (entry.name.startsWith('.')) return [];
        const name = join(prefix, entry.name);
        return entry.isDirectory() ? files(join(directory, entry.name), name) : [name];
    });
}
try {
    const entries = files(source).filter(name =>
        ['manifest.json', 'script.js'].includes(name) || /^(i18n\/.*\.json|images\/.*\.png|logo[^/]*\.png)$/.test(name));
    const archive = join(staging, 'widget.zip');
    execFileSync('zip', ['-q', archive, ...entries], { cwd: source });
    for (const target of ['widget.zip', 'clever-workflow-buttons.zip']) copyFileSync(archive, join(root, target));
    console.log(`Workflow widget packaged: ${entries.length} files, both ZIPs updated.`);
} finally {
    rmSync(staging, { recursive: true, force: true });
}
