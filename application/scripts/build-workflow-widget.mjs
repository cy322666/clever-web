import { execFileSync } from 'node:child_process';
import { copyFileSync, mkdtempSync, readFileSync, readdirSync, rmSync } from 'node:fs';
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

function ensure(condition, message) {
    if (!condition) throw new Error(`Widget validation failed: ${message}`);
}

function validate(entries) {
    const requiredFiles = [
        'manifest.json',
        'script.js',
        'i18n/ru.json',
        'images/logo.png',
        'images/logo_main.png',
        'images/logo_medium.png',
        'images/logo_min.png',
        'images/logo_small.png',
    ];

    requiredFiles.forEach(name => ensure(entries.includes(name), `missing ${name}`));

    const manifest = JSON.parse(readFileSync(join(source, 'manifest.json'), 'utf8'));
    ensure(manifest.widget && manifest.widget.name && manifest.widget.description, 'incomplete widget section');
    ensure(Array.isArray(manifest.locations) && manifest.locations.length > 0, 'locations are missing');
    ensure(Object.prototype.hasOwnProperty.call(manifest, 'settings'), 'settings field is missing');
    ensure(Object.keys(manifest.settings || {}).length > 0, 'settings field is empty');

    const script = readFileSync(join(source, 'script.js'), 'utf8');
    const forbidden = [
        [/\bAMOCRM\b/, 'deprecated AMOCRM global'],
        [/\beval\s*\(/, 'eval call'],
        [/\b(?:alert|confirm)\s*\(/, 'alert or confirm call'],
        [/async\s*:\s*false/, 'synchronous request'],
        [/console\.(?:log|info|warn|error)\s*\(/, 'unsupported console call'],
        [/document\.createElement\s*\(\s*['"]script['"]\s*\)/, 'external script injection'],
    ];

    forbidden.forEach(([pattern, label]) => ensure(!pattern.test(script), label));

    entries.filter(name => /\.(?:js|json|css|md)$/.test(name)).forEach(name => {
        const contents = readFileSync(join(source, name));
        ensure(!(contents[0] === 0xef && contents[1] === 0xbb && contents[2] === 0xbf), `${name} contains BOM`);
        ensure(!contents.includes(Buffer.from('\r\n')), `${name} contains CRLF line endings`);
    });
}

try {
    const entries = files(source).filter(name =>
        ['manifest.json', 'script.js'].includes(name) || /^(i18n\/.*\.json|images\/.*\.png)$/.test(name));
    validate(entries);
    const archive = join(staging, 'widget.zip');
    execFileSync('zip', ['-q', archive, ...entries], { cwd: source });
    for (const target of ['widget.zip', 'clever-workflow-buttons.zip']) copyFileSync(archive, join(root, target));
    console.log(`Workflow widget packaged: ${entries.length} files, both ZIPs updated.`);
} finally {
    rmSync(staging, { recursive: true, force: true });
}
