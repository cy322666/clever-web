import { getQuickJS } from 'quickjs-emscripten';

// Only JSON crosses the WASM boundary. No host functions, files, network or modules are exposed.
const MAX_BYTES = 1024 * 1024;
let input = '';
for await (const chunk of process.stdin) {
    input += chunk;
    if (Buffer.byteLength(input) > MAX_BYTES) throw new Error('Входные данные кода превышают 1 МБ.');
}
const request = JSON.parse(input);
if (typeof request.code !== 'string' || request.code.length > 65536) throw new Error('Код должен быть строкой до 64 КБ.');
const QuickJS = await getQuickJS();
const runtime = QuickJS.newRuntime();
runtime.setMemoryLimit(32 * 1024 * 1024);
runtime.setMaxStackSize(256 * 1024);
const deadline = Date.now() + 1000;
runtime.setInterruptHandler(() => Date.now() > deadline);
const context = runtime.newContext();
let result;
try {
    const data = context.newString(JSON.stringify(request.data || {}));
    context.setProp(context.global, '__input_json', data);
    data.dispose();
    result = context.evalCode(`(() => {
        const data = JSON.parse(__input_json);
        delete globalThis.__input_json;
        const $json = data.json ?? {};
        const $node = data.nodes ?? {};
        const items = Array.isArray($json.items) ? $json.items.map(item => ({ json: item })) : [{ json: $json }];
        const $input = Object.freeze({ all: () => items, first: () => items[0] ?? { json: {} } });
        const logs = [];
        const console = Object.freeze({ log: (...args) => { if (logs.length < 50) logs.push(args.map(v => typeof v === 'string' ? v : JSON.stringify(v)).join(' ').slice(0, 2000)); } });
        const output = (function () { 'use strict';\n${request.code}\n})();
        if (output === undefined) throw new Error('Верните результат через return.');
        if (output && typeof output.then === 'function') throw new Error('Используйте синхронный код; async и внешние запросы здесь недоступны.');
        const serialized = JSON.stringify({ output, logs });
        if (serialized.length > ${MAX_BYTES}) throw new Error('Результат кода превышает 1 МБ.');
        return serialized;
    })()`, 'workflow-node.js');
    if (result.error) {
        const error = context.dump(result.error);
        throw new Error(error.message === 'interrupted' ? 'Превышен лимит выполнения кода (1 секунда).' : (error.message || String(error)));
    }
    const output = context.dump(result.value);
    if (Buffer.byteLength(output) > MAX_BYTES) throw new Error('Результат кода превышает 1 МБ.');
    process.stdout.write(output);
} catch (error) {
    process.stdout.write(JSON.stringify({ error: String(error.message || error).slice(0, 2000) }));
    process.exitCode = 1;
} finally {
    (result?.value || result?.error)?.dispose();
    context.dispose();
    runtime.dispose();
}
