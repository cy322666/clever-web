import { cp, mkdir, readFile } from 'node:fs/promises';
import { resolve } from 'node:path';

// Ship only the code node's runtime dependencies, never development packages or application data.
const root = resolve(import.meta.dirname, '..');
const destination = resolve(root, 'bootstrap/workflow-runtime');
await mkdir(destination, { recursive: true });
await cp(resolve(root, 'resources/workflows/run-javascript.mjs'), resolve(destination, 'run-javascript.mjs'));
const copied = new Set();
async function copyPackage(name) {
    if (copied.has(name)) return;
    copied.add(name);
    const source = resolve(root, 'node_modules', name);
    const manifest = JSON.parse(await readFile(resolve(source, 'package.json'), 'utf8'));
    await mkdir(resolve(destination, 'node_modules', name, '..'), { recursive: true });
    await cp(source, resolve(destination, 'node_modules', name), { recursive: true });
    for (const dependency of Object.keys(manifest.dependencies || {})) await copyPackage(dependency);
}
await copyPackage('quickjs-emscripten');
console.log(`Workflow JavaScript runtime: ${copied.size} packages`);
