import {BrowserWorkflowDocument, clone, flatActions, ports} from './workflow-browser-document.mjs';

// Match WorkflowGraph: ordinary cycles are invalid, a loop's own return and
// explicit done continuation are the only permitted feedback edges.
export function validateDraftGraph(document) {
    const nodes = new Map(document.nodes().map(node => [node.id, node]));
    const edges = document.value.definition.connections;
    if (edges.length > 2000) throw new Error('Слишком много связей.');
    const keys = new Set();
    for (const edge of edges) {
        const source = nodes.get(edge.sourceId), target = nodes.get(edge.targetId);
        if ((!source && edge.sourceId !== 'trigger') || !target || !edge.targetId.startsWith('action:')
            || !(edge.sourceId.startsWith('trigger') ? ['output'] : ports(source)).includes(edge.sourcePort)
            || (edge.sourceId === edge.targetId && edge.sourcePort !== 'done')) throw new Error('Недопустимая связь.');
        const key = [edge.sourceId, edge.sourcePort, edge.targetId].join('|');
        if (keys.has(key)) throw new Error('Эта связь уже существует.');
        if (['yes', 'no'].includes(edge.sourcePort) && target.type !== 'workflow_loop'
            && keys.has([edge.sourceId, edge.sourcePort === 'yes' ? 'no' : 'yes', edge.targetId].join('|')))
            throw new Error('Ветки «Да» и «Нет» нельзя подключить к одной ноде.');
        keys.add(key);
    }
    const loop = id => nodes.get(id)?.type === 'workflow_loop';
    const reach = (pending, candidates, stop = null, opaque = false) => {
        const seen = new Set();
        while (pending.length) {
            const id = pending.pop();
            if (id === stop || seen.has(id)) continue;
            seen.add(id);
            candidates.filter(edge => edge.sourceId === id && (!opaque || !loop(id) || edge.sourcePort === 'done')).forEach(edge => pending.push(edge.targetId));
        }
        return seen;
    };
    const returns = new Set();
    for (const [id] of [...nodes].filter(([id]) => loop(id))) {
        const body = reach(edges.filter(edge => edge.sourceId === id && edge.sourcePort === 'each').map(edge => edge.targetId), edges, id, true);
        edges.forEach(edge => { if (edge.targetId === id && body.has(edge.sourceId) && (!loop(edge.sourceId) || edge.sourcePort === 'done')) returns.add(edge); });
    }
    const forward = edges.filter(edge => !returns.has(edge));
    const feedback = new Set();
    for (const edge of forward) if (loop(edge.sourceId) && edge.sourcePort === 'done') {
        const next = reach([edge.targetId], forward.filter(other => other !== edge));
        const ownReturns = [...returns].filter(other => other.targetId === edge.sourceId);
        if (next.has(edge.sourceId) || ownReturns.some(other => next.has(other.sourceId))
            || forward.some(other => other.sourceId === edge.sourceId && other.sourcePort === 'each' && other.targetId === edge.targetId)) feedback.add(edge);
    }
    const incoming = new Map([...nodes.keys()].filter(id => id.startsWith('action:')).map(id => [id, 0]));
    for (const edge of forward) if (!feedback.has(edge) && !edge.sourceId.startsWith('trigger')) incoming.set(edge.targetId, incoming.get(edge.targetId) + 1);
    const ready = [...incoming].filter(([, count]) => count === 0).map(([id]) => id);
    let visited = 0;
    while (ready.length) {
        const id = ready.pop(); visited++;
        for (const edge of forward) if (!feedback.has(edge) && edge.sourceId === id) {
            const count = incoming.get(edge.targetId) - 1;
            incoming.set(edge.targetId, count);
            if (count === 0) ready.push(edge.targetId);
        }
    }
    if (visited !== incoming.size) throw new Error('Связь создаёт неуправляемый цикл. Используйте ноду «Цикл» или её выход «Готово».');
    return [...returns];
}

// Data adapter for the EXISTING editor. No new forms, cards, CSS or debugger.
// Every write uses Livewire's deferred local state; only explicit server actions
// (Save, Run, settings HTML or reference refresh) send a request.
export class WorkflowEditorDraft {
    constructor(wire, catalog, render = () => {}, announce = () => {}) {
        this.wire = wire;
        this.catalog = catalog;
        this.render = render;
        this.announce = announce;
        this.clipboard = null;
        this.replacing = null;
    }
    definition() {
        return clone({...this.wire.definition, trigger: this.wire.trigger, actions: this.wire.workflowActions});
    }
    edges(definition) {
        if (Array.isArray(definition.connections)) return definition.connections;
        // Exact legacy tree edges were calculated by the existing PHP graph.
        return this.catalog.connections || [];
    }
    model(before = this.definition()) {
        this.refreshCatalog?.();
        const document = new BrowserWorkflowDocument({definition: {...before, connections: this.edges(before)}});
        // Old definitions can omit names. Use the same reference aliases as PHP,
        // without adding names to untouched nodes when writing the graph back.
        for (const node of document.nodes()) {
            const value = document.node(node.id);
            value.name ||= this.catalog.names?.[node.id.replace(/^action:/, '')]
                || this.catalog.actions[value.type]?.name || this.catalog.triggers[value.type]?.name || 'Нода';
        }
        return document;
    }
    mutate(callback, topology = false) {
        const before = this.definition();
        const document = this.model(before);
        const result = callback(document);
        if (topology) validateDraftGraph(document);
        const next = document.value.definition;
        const remaining = new Map(next.actions.map(node => [String(node.id), node]));
        const restore = (steps) => (steps || []).flatMap(old => {
            const value = remaining.get(String(old.id));
            if (!value) return [];
            remaining.delete(String(old.id));
            const node = clone(value);
            if (!Object.hasOwn(old, 'name') && node.name === this.catalog.names?.[old.id]) delete node.name;
            for (const branch of ['true_actions', 'false_actions'])
                if (Object.hasOwn(old.config || {}, branch)) node.config[branch] = restore(old.config[branch]);
            if (Array.isArray(old.config) && !old.config.length && !Object.keys(node.config).length) node.config = [];
            return [node];
        });
        next.actions = [...restore(before.actions), ...remaining.values()];
        for (const [id, old, current] of [
            ['trigger', before.trigger, next.trigger],
            ...(before.additional_triggers || []).map(start => [start.id, start, next.additional_triggers?.find(node => node.id === start.id)]),
        ]) if (old && current) {
            if (Array.isArray(old.config) && !old.config.length && !Object.keys(current.config || {}).length) current.config = [];
            if (!Object.hasOwn(old, 'name') && current.name === this.catalog.names?.[id]) delete current.name;
        }
        if (!topology && !Object.hasOwn(before, 'connections')) delete next.connections;
        this.wire.$set('definition', next, false);
        this.wire.$set('workflowActions', next.actions, false);
        this.wire.$set('trigger', next.trigger, false);
        this.render();
        return result;
    }
    selectActionType(type, openInspector = true, operation = null) {
        const metadata = this.catalog.actions[type];
        if (!metadata) throw new Error('Действие пока недоступно.');
        const item = operation ? this.catalog.operations[operation] : null;
        if (operation && !item) throw new Error('Запрос пока недоступен.');
        const connection = this.wire.insertConnection;
        const hadInspector = (this.wire.mountedActions || []).some(action => ['configureWorkflowAction', 'configureTrigger'].includes(action.name));
        const id = this.mutate(document => {
            const id = document.add({...metadata, name: item?.node_name || metadata.name}, {operation, connection});
            const node = document.node(id);
            node.componentType = type.startsWith('control-') ? type : 'task';
            if (['condition', 'control-condition'].includes(type)) Object.assign(node.config, {true_actions: [], false_actions: []});
            if (type === 'amocrm_find_entity') {
                const entity = node.config.target_entity || 'lead';
                node.config.context_key = 'found_' + entity + '_' + document.value.definition.actions.filter(other => other.type === type && (other.config.target_entity || 'lead') === entity).length;
            }
            if (connection) for (const edge of validateDraftGraph(document)) document.node(edge.targetId).config.require_return = true;
            return id;
        }, true);
        for (const key of ['insertConnection', 'insertActionPath', 'insertActionIndex', 'targetPath']) this.wire.$set(key, null, false);
        this.announce(connection ? 'workflow-node-inserted' : 'workflow-node-created', {nodeId: id, ...connection});
        if (hadInspector && openInspector) return this.wire.openWorkflowInspector(id);
        return id;
    }
    selectReadOperation(operation) { return this.selectActionType('amocrm_read', true, operation); }
    beginTriggerAdd() { this.replacing = null; this.announce('workflow-node-library-open', {mode: 'trigger'}); }
    beginTriggerReplace(id) { this.replacing = id; this.announce('workflow-node-library-open', {mode: 'trigger', replace: true}); }
    selectTriggerType(type, add = false) {
        const metadata = this.catalog.triggers[type];
        if (!metadata) throw new Error('Событие пока недоступно.');
        const replacing = add ? null : this.replacing;
        const id = this.mutate(document => {
            const old = replacing && document.node(replacing);
            if (!old) return document.add(metadata, {trigger: true});
            const name = document.uniqueName(metadata.name, replacing);
            Object.assign(old, {type, config: clone(metadata.defaults), name});
            return replacing;
        }, true);
        this.replacing = null;
        this.announce(replacing ? 'workflow-node-replaced' : 'workflow-node-created', {nodeId: id});
        if (type === 'schedule') return this.wire.openWorkflowInspector(id);
        return id;
    }
    removeTriggerNode(id) {
        this.mutate(document => {
            document.remove(id);
            const d = document.value.definition;
            if (id === 'trigger' && d.additional_triggers?.length) {
                const promoted = d.additional_triggers.shift();
                d.trigger = {...promoted};
                delete d.trigger.id;
                for (const edge of d.connections) if (edge.sourceId === promoted.id) edge.sourceId = 'trigger';
                for (const key of ['canvas_positions', 'canvas_layout']) if (d[key]?.[promoted.id]) {
                    d[key].trigger = d[key][promoted.id];
                    delete d[key][promoted.id];
                }
            }
        }, true);
        this.announce('workflow-connections-updated');
    }
    renameWorkflowNode(id, name, inline = false) {
        // Parked invalid forms remain server-validated with their existing logic.
        // This fallback also remaps the mounted form, without persisting anything.
        if (this.wire.mountedActions?.length || Object.keys(this.wire.workflowInspectorDrafts || {}).length)
            return this.wire.renameWorkflowNode(id, name, inline);
        this.mutate(document => document.rename(id.startsWith('trigger') ? id : 'action:' + id, name));
        return name.trim();
    }
    connectWorkflowNodes(source, port, target, replace = null) {
        this.mutate(document => {
            document.connect(source, port, target, replace);
            for (const edge of validateDraftGraph(document)) document.node(edge.targetId).config.require_return = true;
        }, true);
        this.announce('workflow-connections-updated');
    }
    disconnectWorkflowNodes(source, port, target) {
        this.mutate(document => {
            // Removing a loop return must not silently re-enable implicit
            // iteration. Keep the same require_return guard as the server.
            for (const edge of validateDraftGraph(document)) document.node(edge.targetId).config.require_return = true;
            document.disconnect(source, port, target);
        }, true);
        this.announce('workflow-connections-updated');
    }
    copyWorkflowNodes(ids) {
        const document = this.model();
        const copied = document.copy(ids);
        this.clipboard = document.clipboard;
        return copied;
    }
    pasteWorkflowNodes() {
        return this.mutate(document => { document.clipboard = this.clipboard; return document.paste(); }, true);
    }
    duplicateWorkflowActionStep(id) {
        const find = steps => (steps || []).flatMap(node => [node, ...find(node.config?.true_actions), ...find(node.config?.false_actions)]);
        const source = find(this.wire.workflowActions).find(node => String(node.id) === String(id));
        if (!source) return;
        const clipboard = this.clipboard;
        this.copyWorkflowNodes(find([source]).map(node => 'action:' + node.id));
        const map = this.pasteWorkflowNodes();
        this.clipboard = clipboard;
        this.announce('workflow-nodes-copied', {map});
    }
    renameWorkflow(name) {
        name = name.trim();
        if (!name || [...name].length > 255) throw new Error('Введите название от 1 до 255 символов.');
        this.wire.$set('data.name', name, false);
    }
    setWorkflowTags(tags) {
        tags = [...new Set(tags.map(tag => tag.trim()).filter(Boolean))];
        if (tags.length > 20 || tags.some(tag => [...tag].length > 50)) throw new Error('До 20 тегов по 50 символов.');
        this.wire.$set('definition.tags', tags, false);
    }
    setWorkflowFolder(folder) {
        this.wire.$set('data.group_name', folder || null, false);
        if (this.wire.folder !== undefined) this.wire.$set('folder', folder || null, false);
    }
    updateWorkflowDescription(description) { this.wire.$set('definition.description', description.slice(0, 10000), false); }
}

export function installWorkflowEditorDraft(wire, root) {
    const catalog = JSON.parse(root.querySelector('[data-workflow-draft-catalog]').textContent);
    const announce = (name, detail = {}) => window.dispatchEvent(new CustomEvent(name, {detail}));
    const render = () => {
        const nodes = [...(wire.trigger ? [{...wire.trigger, id: 'trigger'}] : []), ...(wire.definition.additional_triggers || []),
            ...flatActions(wire.workflowActions).map(node => ({...node, id: 'action:' + node.id}))];
        const main = root.querySelector('#workflow-canvas');
        const templates = [...root.querySelectorAll('template[data-workflow-draft-template]')];
        for (const node of nodes) {
            const trigger = node.id.startsWith('trigger');
            let shell = [...main.querySelectorAll('[data-workflow-node-id]')].find(el => el.dataset.workflowNodeId === node.id);
            if (shell?.querySelector('[data-workflow-node-card]') && (!trigger || shell.dataset.draftType === node.type)) continue;
            const key = trigger ? 'trigger:' + node.type : node.type === 'amocrm_read' ? 'read:' + node.config.operation : 'action:' + node.type;
            const template = templates.find(el => el.dataset.workflowDraftTemplate === key) || templates.find(el => el.dataset.workflowDraftTemplate === 'action:' + node.type);
            if (!template) throw new Error('Шаблон ноды не загружен. Обновите страницу.');
            const card = template.content.firstElementChild.cloneNode(true);
            // DOM attribute assignment prevents markup injection. Expression
            // literals must also escape legacy imported IDs, not only new UUIDs.
            for (const element of [card, ...card.querySelectorAll('*')]) for (const attribute of [...element.attributes]) {
                const literal = /^(x-|wire:)/.test(attribute.name) && attribute.name !== 'wire:key';
                const escape = value => literal ? JSON.stringify(value).slice(1, -1).replaceAll("'", "\\'") : value;
                element.setAttribute(attribute.name, attribute.value.replaceAll('__DRAFT_ID__', escape(node.id.replace(/^action:/, ''))).replaceAll('__DRAFT_START__', escape(node.id)));
            }
            window.Alpine.mutateDom(() => {
                if (shell) { window.Alpine.destroyTree(shell); shell.replaceWith(card); }
                else (trigger ? main.querySelector('.workflow-node-starts') : main).append(card);
                window.Alpine.initTree(card);
            });
        }
        for (const shell of main.querySelectorAll('[data-workflow-node-id]')) {
            if (!nodes.some(node => node.id === shell.dataset.workflowNodeId)) {
                window.Alpine.mutateDom(() => { window.Alpine.destroyTree(shell); shell.remove(); });
            }
        }
        // The original empty-trigger affordance is reused, not redesigned.
        if (!wire.trigger && !main.querySelector('.workflow-node-empty')) {
            const template = templates.find(el => el.dataset.workflowDraftTemplate === 'empty');
            const empty = template.content.firstElementChild.cloneNode(true);
            window.Alpine.mutateDom(() => { main.querySelector('.workflow-node-starts').append(empty); window.Alpine.initTree(empty); });
        }
        announce('workflow-connections-updated');
    };
    const draft = new WorkflowEditorDraft(wire, catalog, render, announce);
    let catalogSource = root.querySelector('[data-workflow-draft-catalog]').textContent;
    draft.refreshCatalog = () => {
        const source = root.querySelector('[data-workflow-draft-catalog]').textContent;
        if (source !== catalogSource) { draft.catalog = JSON.parse(source); catalogSource = source; }
    };
    // Canvas geometry keeps its existing API, but pure graph methods are local.
    draft.adapter = new Proxy(wire, {get(target, key) {
        if (typeof draft[key] === 'function' && !['definition', 'mutate', 'model', 'render', 'announce', 'edges', 'refreshCatalog'].includes(key))
            return async (...params) => draft[key](...params);
        return target[key];
    }});
    return draft;
}
