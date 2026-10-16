// The editable document has one owner. No RPCs, DOM or framework dependencies here.
export const clone = (value) => JSON.parse(JSON.stringify(value));
const unsafe = new Set(['__proto__', 'prototype', 'constructor']);
export function getPath(object, path, fallback = '') {
    return (
        String(path)
            .split('.')
            .reduce((value, key) => (unsafe.has(key) ? undefined : value?.[key]), object) ?? fallback
    );
}
export function setPath(object, path, value) {
    const keys = String(path).split('.');
    if (keys.some((key) => unsafe.has(key))) throw new Error('Недопустимое имя поля.');
    let cursor = object;
    keys.slice(0, -1).forEach((key, index) => {
        if (!cursor[key] || typeof cursor[key] !== 'object')
            cursor[key] = /^\d+$/.test(keys[index + 1]) ? [] : {};
        cursor = cursor[key];
    });
    cursor[keys.at(-1)] = value;
}
export const ports = (node) =>
    node.type === 'workflow_loop'
        ? ['each', 'done']
        : ['condition', 'control-condition'].includes(node.type)
          ? ['yes', 'no']
          : ['output'];

/** Paths inside one list item, never positional array indexes. */
export function collectionItemFields(items) {
    if (!Array.isArray(items)) return [];
    const paths = new Set();
    const visit = (value, prefix = '', depth = 0) => {
        if (!value || typeof value !== 'object' || Array.isArray(value) || depth > 2) return;
        for (const [key, child] of Object.entries(value).slice(0, 40)) {
            if (!/^[\p{L}\p{N}_-]+$/u.test(key) || /^\d+$/.test(key)) continue;
            const path = prefix ? prefix + '.' + key : key;
            paths.add(path);
            if (paths.size >= 100) return;
            visit(child, path, depth + 1);
        }
    };
    items.slice(0, 20).forEach((item) => visit(item));
    return [...paths];
}

export function filterItemFields(sources, expression) {
    const normalize = (value) => String(value ?? '').trim()
        .replace(/^\{\{\s*|\s*\}\}$/g, '')
        .replace(/\$node\[(["'])(.*?)\1\]\.json/g, (_, quote, name) => '$(' + JSON.stringify(name) + ')')
        .replace(/\$\('([^']*)'\)/g, (_, name) => '$(' + JSON.stringify(name) + ')')
        .replace(/(\$\("(?:[^"\\]|\\.)*"\))\.json(?=\.|$)/g, '$1');
    const selected = normalize(expression);
    if (!selected) return [];
    if (selected.startsWith('[')) {
        try { return collectionItemFields(JSON.parse(selected)); } catch { return []; }
    }
    const fields = sources.flatMap((source) => source.fields || []);
    const field = fields.find((item) => item.type === 'array' && normalize(item.expression) === selected);
    if (field) return field.item_fields || [];
    if (selected === '$json.items')
        return fields.find((item) => item.type === 'array' && normalize(item.expression) === '$json')?.item_fields || [];
    return [];
}

export function applyTriggerDisplayInput(raw, visible) {
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return visible;
    const input = clone(raw);
    if (input.item && typeof input.item === 'object') {
        input.item = visible;
        if (input.entity && Object.hasOwn(input, input.entity)) input[input.entity] = visible;
        if (input.action && Object.hasOwn(input, input.action)) input[input.action] = visible;
        const idKey = input.entity + '_id';
        if (input.payload && Object.hasOwn(input.payload, idKey) && visible?.id != null)
            input.payload[idKey] = visible.id;
    }
    else if (Object.hasOwn(input, 'body')) {
        const body = input.body;
        if (body?.account && typeof body.account === 'object') {
            const collections = ['leads', 'contacts', 'companies', 'customers', 'tasks', 'talks', 'messages', 'notes', 'unsorted'];
            const slots = collections.flatMap((kind) => Object.values(body[kind] || {}).flatMap((items) =>
                Array.isArray(items) ? items.map((_, index) => ({ kind, items, index })) : []));
            if (slots.length === 1) slots[0].items[slots[0].index] = visible;
            else input.body = visible;
        } else input.body = visible;
    } else if (Object.hasOwn(input, 'payload')) input.payload = visible;
    else if (visible && typeof visible === 'object' && !Array.isArray(visible)) Object.assign(input, visible);
    else return visible;
    return input;
}
const uuid = (prefix) =>
    prefix +
    (globalThis.crypto?.randomUUID?.() ||
        `${Date.now().toString(36)}_${Math.random().toString(36).slice(2)}`);

export function flatActions(actions = []) {
    return actions.flatMap((node) => {
        const copy = clone(node);
        if (!copy.config || Array.isArray(copy.config)) copy.config = {};
        delete copy.config?.true_actions;
        delete copy.config?.false_actions;
        return [
            copy,
            ...flatActions(node.config?.true_actions || []),
            ...flatActions(node.config?.false_actions || []),
        ];
    });
}

// Replace only syntactic node references, including those inside JSON strings.
// Never rewrite an ordinary matching name or execute user JavaScript.
export function remapReferences(value, names) {
    if (Array.isArray(value)) return value.map((item) => remapReferences(item, names));
    if (value && typeof value === 'object')
        return Object.fromEntries(
            Object.entries(value).map(([key, item]) => [
                key,
                key === 'javascript_code' ? item : remapReferences(item, names),
            ]),
        );
    if (typeof value !== 'string' || !value.includes('{{')) return value;
    const replacements = new Map(),
        single = (value) => "'" + String(value).replaceAll('\\', '\\\\').replaceAll("'", "\\'") + "'";
    for (const [before, after] of Object.entries(names)) {
        for (const quote of [JSON.stringify, single])
            for (const [prefix, suffix] of [
                ['$(', ')'],
                ['$node[', '].json'],
            ]) {
                const old = prefix + quote(before) + suffix,
                    next = prefix + quote(after) + suffix;
                replacements.set(old, next);
                replacements.set(JSON.stringify(old).slice(1, -1), JSON.stringify(next).slice(1, -1));
            }
    }
    if (!replacements.size) return value;
    const escape = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const pattern = new RegExp(
        [...replacements.keys()]
            .sort((a, b) => b.length - a.length)
            .map(escape)
            .join('|'),
        'g',
    );
    return value.replace(/\{\{[\s\S]*?\}\}/g, (expression) =>
        expression.replace(pattern, (token) => replacements.get(token)),
    );
}

export class BrowserWorkflowDocument {
    constructor(document) {
        this.value = clone(document);
        this.value.definition ||= { actions: [], trigger: null, connections: [] };
        this.value.definition.actions = flatActions(this.value.definition.actions);
        this.value.definition.connections ||= [];
        for (const start of [
            this.value.definition.trigger,
            ...(this.value.definition.additional_triggers || []),
        ].filter(Boolean)) {
            if (!start.config || Array.isArray(start.config)) start.config = {};
        }
        this.clipboard = null;
        this.revision = 0;
        this.saved = JSON.stringify(this.value);
        this.undoStack = [];
        this.redoStack = [];
    }
    get dirty() {
        return JSON.stringify(this.value) !== this.saved;
    }
    checkpoint(group = null) {
        const now = Date.now();
        if (group && this.editGroup === group && now - this.editAt < 600) {
            this.editAt = now;
            this.revision++;
            return;
        }
        this.editGroup = group;
        this.editAt = now;
        const current = JSON.stringify(this.value);
        if (this.undoStack.at(-1) !== current) this.undoStack.push(current);
        if (this.undoStack.length > 50) this.undoStack.shift();
        this.redoStack = [];
        this.revision++;
    }
    undo(redo = false) {
        const from = redo ? this.redoStack : this.undoStack,
            to = redo ? this.undoStack : this.redoStack;
        if (!from.length) return false;
        to.push(JSON.stringify(this.value));
        this.value = JSON.parse(from.pop());
        this.revision++;
        return true;
    }
    starts() {
        const d = this.value.definition;
        return [...(d.trigger ? [{ ...d.trigger, id: 'trigger' }] : []), ...(d.additional_triggers || [])];
    }
    nodes() {
        return [
            ...this.starts(),
            ...this.value.definition.actions.map((node) => ({ ...node, id: 'action:' + node.id })),
        ];
    }
    node(id) {
        if (id === 'trigger') return this.value.definition.trigger;
        return id?.startsWith('trigger:')
            ? this.value.definition.additional_triggers?.find((node) => node.id === id)
            : this.value.definition.actions.find((node) => 'action:' + node.id === id);
    }
    uniqueName(name, except = null) {
        const names = new Set(
            this.nodes()
                .filter((node) => node.id !== except)
                .map((node) => node.name),
        );
        let next = name,
            index = 2;
        while (names.has(next)) next = `${name} (${index++})`;
        return next;
    }
    rename(id, proposed) {
        const node = this.node(id),
            name = proposed.trim();
        if (!node || !name || name.length > 100)
            throw new Error('Название должно содержать от 1 до 100 символов.');
        if (this.uniqueName(name, id) !== name) throw new Error('Нода с таким названием уже есть.');
        if (node.name === name) return;
        this.checkpoint('rename:' + id);
        const names = { [node.name]: name };
        node.name = name;
        // All node configurations, including triggers, participate in reference remapping.
        const d = this.value.definition;
        d.actions = remapReferences(d.actions, names);
        if (d.trigger) d.trigger.config = remapReferences(d.trigger.config, names);
        (d.additional_triggers || []).forEach((start) => {
            start.config = remapReferences(start.config, names);
        });
    }
    add(metadata, { trigger = false, operation = null, connection = null } = {}) {
        if (
            connection &&
            (!this.node(connection.sourceId) || (connection.targetId && !this.node(connection.targetId)))
        )
            throw new Error('Выбранная связь уже удалена.');
        this.checkpoint();
        const d = this.value.definition;
        const node = {
            id: uuid(trigger ? 'trigger:' : 'step_'),
            type: metadata.type,
            name: this.uniqueName(metadata.name),
            config: clone(metadata.defaults || {}),
        };
        if (Array.isArray(node.config)) node.config = {};
        if (operation) {
            node.config.operation = operation;
            node.config.body_mode = /^(leads|contacts|companies|customers|tasks)\.list$/.test(operation)
                ? 'builder'
                : 'fields';
        }
        if (['condition', 'control-condition'].includes(node.type))
            Object.assign(node.config, { has_true_branch: true, has_false_branch: true });
        let id = node.id;
        if (trigger) {
            if (!d.trigger) {
                delete node.id;
                d.trigger = node;
                id = 'trigger';
            } else {
                d.additional_triggers ||= [];
                if (d.additional_triggers.length >= 20)
                    throw new Error('Доступно до 20 дополнительных запусков.');
                d.additional_triggers.push(node);
            }
        } else {
            if (d.actions.length >= 500) throw new Error('Доступно до 500 нод.');
            d.actions.push(node);
            id = 'action:' + node.id;
            if (connection) {
                d.connections = d.connections.filter(
                    (edge) =>
                        !(
                            edge.sourceId === connection.sourceId &&
                            edge.sourcePort === connection.sourcePort &&
                            edge.targetId === connection.targetId
                        ),
                );
                d.connections.push({
                    sourceId: connection.sourceId,
                    sourcePort: connection.sourcePort,
                    targetId: id,
                });
                if (connection.targetId)
                    d.connections.push({
                        sourceId: id,
                        sourcePort: ports(node)[0],
                        targetId: connection.targetId,
                    });
                else if (connection.sourcePort === 'each' && this.node(connection.sourceId)?.type === 'workflow_loop') {
                    // A node replacing the loop placeholder must return to the loop.
                    // Inserting another node on this edge can extend the body later.
                    const returnPorts = node.type === 'workflow_loop' ? ['done']
                        : ['condition', 'control-condition'].includes(node.type) ? ['yes', 'no'] : ['output'];
                    returnPorts.forEach((port) => d.connections.push({ sourceId: id, sourcePort: port, targetId: connection.sourceId }));
                }
            }
        }
        return id;
    }
    connect(sourceId, sourcePort, targetId, replaceTarget = null) {
        const source = this.node(sourceId),
            target = this.node(targetId);
        if (
            !source ||
            !target ||
            !targetId.startsWith('action:') ||
            !(sourceId.startsWith('trigger') ? ['output'] : ports(source)).includes(sourcePort)
        )
            throw new Error('Недопустимая связь.');
        if (sourceId === targetId && sourcePort !== 'done') throw new Error('Нельзя соединить ноду с собой.');
        const connections = this.value.definition.connections;
        if (
            connections.some(
                (edge) =>
                    edge.sourceId === sourceId &&
                    edge.sourcePort === sourcePort &&
                    edge.targetId === targetId,
            )
        )
            return;
        if (
            ['yes', 'no'].includes(sourcePort) &&
            target.type !== 'workflow_loop' &&
            connections.some(
                (edge) =>
                    edge.sourceId === sourceId &&
                    edge.targetId === targetId &&
                    edge.sourcePort !== sourcePort,
            )
        )
            throw new Error('Ветки «Да» и «Нет» нельзя подключить к одной ноде.');
        this.checkpoint();
        this.value.definition.connections = connections.filter(
            (edge) =>
                !(
                    replaceTarget &&
                    edge.sourceId === sourceId &&
                    edge.sourcePort === sourcePort &&
                    edge.targetId === replaceTarget
                ),
        );
        this.value.definition.connections.push({ sourceId, sourcePort, targetId });
    }
    disconnect(sourceId, sourcePort, targetId) {
        this.checkpoint();
        this.value.definition.connections = this.value.definition.connections.filter(
            (edge) =>
                !(edge.sourceId === sourceId && edge.sourcePort === sourcePort && edge.targetId === targetId),
        );
    }
    remove(id) {
        if (!this.node(id)) return;
        this.checkpoint();
        const d = this.value.definition,
            node = this.node(id),
            edges = d.connections;
        d.connections = edges.filter((edge) => edge.sourceId !== id && edge.targetId !== id);
        if (!['condition', 'control-condition', 'workflow_loop'].includes(node.type))
            edges
                .filter((edge) => edge.targetId === id)
                .forEach((input) => {
                    edges
                        .filter((edge) => edge.sourceId === id && edge.sourcePort === 'output')
                        .forEach((output) => {
                            const bridge = { ...input, targetId: output.targetId };
                            if (
                                bridge.sourceId !== bridge.targetId &&
                                !d.connections.some((edge) => JSON.stringify(edge) === JSON.stringify(bridge))
                            )
                                d.connections.push(bridge);
                        });
                });
        if (id === 'trigger') d.trigger = null;
        else if (id.startsWith('trigger:'))
            d.additional_triggers = d.additional_triggers.filter((node) => node.id !== id);
        else d.actions = d.actions.filter((node) => 'action:' + node.id !== id);
        for (const key of ['canvas_positions', 'canvas_layout']) if (d[key]) delete d[key][id];
    }
    copy(ids) {
        const selected = new Set(ids);
        this.clipboard = {
            nodes: clone(this.nodes().filter((node) => selected.has(node.id))),
            connections: clone(
                this.value.definition.connections.filter(
                    (edge) => selected.has(edge.sourceId) && selected.has(edge.targetId),
                ),
            ),
        };
        return this.clipboard.nodes.length > 0;
    }
    paste() {
        if (!this.clipboard?.nodes.length) return {};
        const actions = this.clipboard.nodes.filter((node) => node.id.startsWith('action:')).length;
        if (
            this.value.definition.actions.length + actions > 500 ||
            this.starts().length + this.clipboard.nodes.length - actions > 21
        )
            throw new Error('Копия превышает лимит нод сценария.');
        const mapping = {},
            names = {},
            additions = [];
        // Reserve names as each copied node is inserted, then remap references only inside copies.
        this.clipboard.nodes.forEach((original) => {
            const id = this.add(
                { type: original.type, name: original.name, defaults: original.config },
                { trigger: original.id.startsWith('trigger') },
            );
            const node = this.node(id);
            Object.assign(node, clone(original), {
                id: id.startsWith('action:') ? id.slice(7) : id,
                name: node.name,
            });
            if (id === 'trigger') delete node.id;
            mapping[original.id] = id;
            names[original.name] = node.name;
            additions.push(node);
        });
        additions.forEach((node) => {
            node.config = remapReferences(node.config, names);
        });
        this.value.definition.connections.push(
            ...this.clipboard.connections.map((edge) => ({
                ...edge,
                sourceId: mapping[edge.sourceId],
                targetId: mapping[edge.targetId],
            })),
        );
        return mapping;
    }
}
