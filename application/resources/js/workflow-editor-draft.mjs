import {BrowserWorkflowDocument, clone, flatActions, getPath, ports, setPath} from './workflow-browser-document.mjs';
import {nodeUndoEntry, restoreNodeEdit} from './workflow-node-undo.mjs';

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

const draftActions = new Set([
    'save', 'create', 'toggleWorkflowActivation', 'exportCurrentWorkflow', 'runWorkflowTest',
    'runWorkflowCanvasNode', 'runEditingWorkflowNode', 'startWorkflowDebug', 'nextWorkflowDebugStep',
    'editWorkflowDebugInput', 'previewDebugInput', 'openWorkflowDebugger', 'refreshWorkflowReference',
    'refreshEditingNodeReferences', 'openWorkflowInspector', 'openWorkflowInspectorCreateOption',
    'callMountedAction', 'reorderWorkflowActions', 'saveWorkflowCredential', 'deleteWorkflowCredential',
]);

const aliases = {trigger: 'definition.trigger', workflowActions: 'definition.actions'};
const transportPaths = ['definition', 'workflowActions', 'trigger', 'data.name', 'data.group_name', 'folder',
    'workflowLocalInspectorDirty', 'editingActionId'];
const equal = (a, b) => a === b || JSON.stringify(a) === JSON.stringify(b);
const copy = value => value === undefined ? undefined : clone(value);

// Apply server normalization to fields untouched while the request was in flight.
function reconcile(sent, current, received) {
    if (equal(sent, received)) return current;
    if (equal(sent, current)) return copy(received);
    if (Array.isArray(sent) && Array.isArray(current) && Array.isArray(received) &&
        sent.length === current.length && sent.length === received.length &&
        sent.every((item, index) => item?.id != null && item.id === current[index]?.id && item.id === received[index]?.id)) {
        return current.map((item, index) => reconcile(sent[index], item, received[index]));
    }
    if (sent == null || current == null || received == null ||
        [sent, current, received].some(value => typeof value !== 'object' || Array.isArray(value))) return current;
    return Object.fromEntries([...new Set([...Object.keys(current), ...Object.keys(received)])].flatMap(key => {
        const result = reconcile(sent[key], current[key], received[key]);
        return result === undefined ? [] : [[key, result]];
    }));
}

function nodeConfigs(definition) {
    return new Map([['trigger', definition.trigger?.config],
        ...(definition.additional_triggers || []).map(node => [node.id, node.config]),
        ...flatActions(definition.actions).map(node => ['action:' + node.id, node.config])]);
}

// The browser owns the editable definition. The trigger and action tree are
// views of that same object, including in the canvas and inspector.
export class WorkflowEditorDraft {
    constructor(wire, catalog, render = () => {}, announce = () => {}, canvas = () => null, reactive = value => value) {
        this.state = reactive({
            definition: this.readDefinition(wire),
            data: {name: wire.data?.name, group_name: wire.data?.group_name},
            workflowLocalInspectorDirty: copy(wire.workflowLocalInspectorDirty) ?? {},
            insertConnection: null,
            insertActionPath: null,
            insertActionIndex: null,
            targetPath: null,
            folder: typeof wire.folder === 'function' ? undefined : wire.folder,
            editingActionId: wire.editingActionId,
            editingTriggerNodeId: wire.editingTriggerNodeId,
        });
        this.dirtyRevision = 0;
        this.wire = new Proxy({}, {
            get: (_, key) => {
                if (key === '$set') return (path, value, live) => {
                    const localPath = Object.hasOwn(aliases, path) ? aliases[path] : path;
                    if (Object.hasOwn(this.state, localPath.split('.')[0])) setPath(this.state, localPath, value);
                    else return wire.$set(path, value, live);
                };
                if (key === 'data') return {...wire.data, ...this.state.data};
                if (Object.hasOwn(aliases, key)) return this.state.definition[key === 'trigger' ? 'trigger' : 'actions'];
                return Object.hasOwn(this.state, key) ? this.state[key] : wire[key];
            },
        });
        this.catalog = catalog;
        this.render = render;
        this.announce = announce;
        this.canvas = canvas;
        this.undoStack = [];
        this.clipboard = null;
        this.replacing = null;
    }
    readDefinition(wire) { return clone({...wire.definition, trigger: wire.trigger, actions: wire.workflowActions}); }
    definition() { return clone(this.state.definition); }

    inspectorChanged(nodeId) {
        this.state.workflowLocalInspectorDirty = {...this.state.workflowLocalInspectorDirty, [nodeId]: ++this.dirtyRevision};
    }

    attachTransport(wire) {
        return wire.$interceptMessage(({message, onSuccess}) => {
            if (!message.payload.calls.some(call => draftActions.has(call.method)) && !Object.hasOwn(message.payload.updates, 'debugStartNodeId')) return;
            this.flushInspector?.();
            const sent = this.definition();
            const identity = clone(this.state.data);
            const dirty = clone(this.state.workflowLocalInspectorDirty);
            const values = {
                definition: sent, workflowActions: sent.actions, trigger: sent.trigger,
                'data.name': identity.name, 'data.group_name': identity.group_name, folder: this.state.folder,
                workflowLocalInspectorDirty: Object.keys(dirty).length ? Object.fromEntries(Object.keys(dirty).map(id => [id, true])) : [],
                editingActionId: this.state.editingActionId,
            };
            const {updates} = message.payload;
            for (const path of transportPaths) {
                const value = values[path];
                if (value === undefined) continue;
                const baseline = getPath(message.component.canonical, path, null);
                for (const key of Object.keys(updates)) if (key === path || key.startsWith(path + '.')) delete updates[key];
                if (!equal(value, baseline)) updates[path] = value;
            }
            // Livewire's request hooks can cache the body before message hooks run.
            // Keep the outgoing body and the updates used by snapshot merge in sync.
            message.request.options.body = JSON.stringify(message.request.payload);
            onSuccess(({onSync}) => {
                // Seed Livewire's merge with what this message sent. Do it at
                // response time so preview polling never receives pending edits.
                for (const path of transportPaths) if (Object.hasOwn(updates, path)) wire.$set(path, copy(updates[path]), false);
                onSync(() => this.acceptResponse(message.component.canonical, sent, identity, dirty));
            });
        });
    }

    acceptResponse(server, sent, identity, dirty) {
        const current = this.state.definition;
        const received = this.readDefinition(server);
        const next = reconcile(sent, current, received);
        this.state.data = reconcile(identity, this.state.data, {name: server.data?.name, group_name: server.data?.group_name});
        const configs = nodeConfigs(next), acknowledged = nodeConfigs(received);
        this.state.workflowLocalInspectorDirty = Object.fromEntries(Object.entries(this.state.workflowLocalInspectorDirty).filter(([id, revision]) =>
            revision !== dirty[id] || server.workflowLocalInspectorDirty?.[id] || !equal(configs.get(id), acknowledged.get(id))));
        if (!equal(next, current)) this.applyDefinition(next);
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
    mutate(callback, topology = false, trackNodes = false) {
        const before = this.definition();
        const canvas = trackNodes ? this.canvas() : null;
        const points = {...before.canvas_positions, ...canvas?.cardPositions()};
        if (canvas) before.canvas_edge_routes = clone(canvas.edgeRoutes);
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
        const entry = trackNodes ? nodeUndoEntry(before, next, this.edges(before), next.connections, points, trackNodes.promoted) : null;
        if (entry && before.canvas_edge_routes) {
            next.canvas_edge_routes = Object.fromEntries(next.connections.flatMap(edge => {
                const key = JSON.stringify([edge.sourceId, edge.sourcePort, edge.targetId]);
                const previousSource = entry.promoted && edge.sourceId === 'trigger' ? entry.promoted : edge.sourceId;
                const previousKey = JSON.stringify([previousSource, edge.sourcePort, edge.targetId]);
                const route = before.canvas_edge_routes[previousKey];
                return route ? [[key, route]] : [];
            }));
            if (canvas) canvas.edgeRoutes = next.canvas_edge_routes;
        }
        this.applyDefinition(next);
        if (canvas && entry?.promoted && points[entry.promoted]) {
            canvas.restoreCardPositions({...canvas.pendingCardPositions, trigger:points[entry.promoted]});
        }
        if (entry) this.rememberUndo({nodes: entry});
        return result;
    }
    applyDefinition(next) {
        this.state.definition = next;
        this.render();
        this.announce('workflow-local-document-changed',{componentId:this.wire.$id});
    }
    rememberUndo(entry) {
        this.undoStack.push(entry);
        if (this.undoStack.length > 50) this.undoStack.shift();
    }
    rememberMove(before, after) {
        const points = Object.fromEntries(Object.entries(before).filter(([id, point]) =>
            after[id] && (Math.abs(after[id].x - point.x) > 0.01 || Math.abs(after[id].y - point.y) > 0.01)));
        if (Object.keys(points).length) this.rememberUndo({points});
    }
    rememberInsertionPlacement(id, before, after) {
        const entry = this.undoStack.findLast(item => item.nodes?.created.has(id))?.nodes;
        if (!entry) return;
        for (const [nodeId, point] of Object.entries(before)) {
            if (after[nodeId] && (after[nodeId].x !== point.x || after[nodeId].y !== point.y)) {
                entry.coordinates[nodeId] = point;
            }
        }
    }
    undo() {
        const entry = this.undoStack.at(-1);
        if (!entry) return false;
        const canvas = this.canvas();
        if (entry.nodes) {
            this.refreshCatalog?.();
            const next = this.definition();
            const points = {...next.canvas_positions, ...canvas?.cardPositions()};
            const remaining = restoreNodeEdit(entry.nodes, next, this.edges(next), points, canvas?.edgeRoutes ?? next.canvas_edge_routes, this.catalog.connections || []);
            validateDraftGraph(this.model(next));
            const coordinates = next.canvas_positions;
            const edited = this.wire.editingActionId ? 'action:' + this.wire.editingActionId : this.wire.editingTriggerNodeId;
            if (edited && (!remaining.has(edited) || edited === 'trigger' && entry.nodes.promoted)) {
                this.announce('workflow-inspector-close', {componentId: this.wire.$id});
            }
            if (canvas) canvas.edgeRoutes = next.canvas_edge_routes;
            this.applyDefinition(next);
            if (canvas) {
                canvas.selectedNodeIds = canvas.selectedNodeIds.filter(id => remaining.has(id));
                canvas.restoreCardPositions(coordinates);
                canvas.saveNodeLayout();
                canvas.applyNodeSelection();
            }
        } else if (canvas) {
            canvas.restoreCardPositions({...canvas.pendingCardPositions, ...entry.points});
        } else return false;
        canvas?.scheduleGraphRefreshAfterPaint();
        this.undoStack.pop();
        return true;
    }
    selectActionType(type, openInspector = true, operation = null) {
        const metadata = this.catalog.actions[type];
        if (!metadata) throw new Error('Действие пока недоступно.');
        const item = operation ? this.catalog.operations[operation] : null;
        if (operation && !item) throw new Error('Запрос пока недоступен.');
        const connection = this.wire.insertConnection;
        const hadInspector = this.inspectorOpened || (this.wire.mountedActions || []).some(action => ['configureWorkflowAction', 'configureTrigger'].includes(action.name));
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
        }, true, true);
        for (const key of ['insertConnection', 'insertActionPath', 'insertActionIndex', 'targetPath']) this.wire.$set(key, null, false);
        this.announce(connection ? 'workflow-node-inserted' : 'workflow-node-created', {nodeId: id, ...connection});
        if (hadInspector && openInspector) this.announce('workflow-inspector-open',{componentId:this.wire.$id,nodeId:id,name:item?.node_name || metadata.name});
        return id;
    }
    selectReadOperation(operation) { return this.selectActionType('amocrm_read', true, operation); }
    beginTriggerAdd() { this.replacing = null; this.wire.$set('insertConnection', null, false); this.announce('workflow-node-library-open', {mode: 'trigger'}); }
    beginTriggerReplace(id) { this.replacing = id; this.wire.$set('insertConnection', null, false); this.announce('workflow-node-library-open', {mode: 'trigger', replace: true}); }
    selectTriggerType(type, add = false) {
        if (this.wire.insertConnection) throw new Error('Запуск нельзя добавить внутри ветки.');
        const metadata = this.catalog.triggers[type];
        if (!metadata) throw new Error('Событие пока недоступно.');
        const replacing = add ? null : this.replacing;
        const id = this.mutate(document => {
            const old = replacing && document.node(replacing);
            if (!old) return document.add(metadata, {trigger: true});
            const name = document.uniqueName(metadata.name, replacing);
            Object.assign(old, {type, config: clone(metadata.defaults), name});
            return replacing;
        }, true, !replacing);
        this.replacing = null;
        this.announce(replacing ? 'workflow-node-replaced' : 'workflow-node-created', {nodeId: id});
        if (type === 'schedule') this.announce('workflow-inspector-open',{componentId:this.wire.$id,nodeId:id,name:metadata.name});
        return id;
    }
    removeWorkflowAction(id) {
        this.mutate(document => document.remove('action:' + id), true, true);
        this.announce('workflow-connections-updated');
    }
    removeTriggerNode(id) {
        const promoted = id === 'trigger' ? this.wire.definition.additional_triggers?.[0]?.id : null;
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
        }, true, {promoted});
        this.announce('workflow-connections-updated');
    }
    renameWorkflowNode(id, name, inline = false) {
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
        return this.mutate(document => { document.clipboard = this.clipboard; return document.paste(); }, true, true);
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
    setWorkflowFolder(folder) {
        this.wire.$set('data.group_name', folder || null, false);
        if (this.wire.folder !== undefined) this.wire.$set('folder', folder || null, false);
    }
    updateWorkflowDescription(description) { this.wire.$set('definition.description', description.slice(0, 10000), false); }
}

export function installWorkflowEditorDraft(wire, root, canvas) {
    const catalog = JSON.parse(root.querySelector('[data-workflow-draft-catalog]').textContent);
    const announce = (name, detail = {}) => window.dispatchEvent(new CustomEvent(name, {detail}));
    const retiredCards = new Map();
    const retire = shell => {
        for (const node of [shell, ...shell.querySelectorAll('[data-workflow-node-id]')]) {
            if (!node.querySelector('[data-workflow-node-card]')) continue;
            const card = node.cloneNode(true);
            card.querySelectorAll('[data-workflow-node-id]').forEach(child => child.remove());
            retiredCards.set(node.dataset.workflowNodeId + '|' + (node.dataset.draftType || ''), card);
            if (retiredCards.size > 500) retiredCards.delete(retiredCards.keys().next().value);
        }
    };
    const render = () => {
        const {trigger, actions, additional_triggers = []} = draft.state.definition;
        const nodes = [...(trigger ? [{...trigger, id: 'trigger'}] : []), ...additional_triggers,
            ...flatActions(actions).map(node => ({...node, id: 'action:' + node.id}))];
        const main = root.querySelector('#workflow-canvas');
        announce('workflow-canvas-before-update', {componentId: wire.$id});
        const ids = new Set(nodes.map(node => node.id));
        const shells = new Map();
        for (const shell of main.querySelectorAll('[data-workflow-node-id]')) {
            if (!main.contains(shell) || shell.hasAttribute('data-workflow-start-add')) continue;
            if (ids.has(shell.dataset.workflowNodeId)) { shells.set(shell.dataset.workflowNodeId, shell); continue; }
            retire(shell);
            window.Alpine.mutateDom(() => { window.Alpine.destroyTree(shell); shell.remove(); });
        }
        const templates = [...root.querySelectorAll('template[data-workflow-draft-template]')];
        for (const node of nodes) {
            const trigger = node.id.startsWith('trigger');
            const shell = shells.get(node.id);
            if (shell?.querySelector('[data-workflow-node-card]') && (!trigger || shell.dataset.draftType === node.type)) continue;
            const key = trigger ? 'trigger:' + node.type : node.type === 'amocrm_read' ? 'read:' + node.config.operation : 'action:' + node.type;
            const template = templates.find(el => el.dataset.workflowDraftTemplate === key) || templates.find(el => el.dataset.workflowDraftTemplate === 'action:' + node.type);
            const retiredKey = node.id + '|' + (trigger ? node.type : '');
            const card = (template?.content.firstElementChild ?? retiredCards.get(retiredKey))?.cloneNode(true);
            if (!card) throw new Error('Шаблон ноды не загружен. Обновите страницу.');
            retiredCards.delete(retiredKey);
            // DOM attribute assignment prevents markup injection. Expression
            // literals must also escape legacy imported IDs, not only new UUIDs.
            for (const element of [card, ...card.querySelectorAll('*')]) for (const attribute of [...element.attributes]) {
                const literal = /^(x-|wire:)/.test(attribute.name) && attribute.name !== 'wire:key';
                const escape = value => literal ? JSON.stringify(value).slice(1, -1).replaceAll("'", "\\'") : value;
                element.setAttribute(attribute.name, attribute.value.replaceAll('__DRAFT_ID__', escape(node.id.replace(/^action:/, ''))).replaceAll('__DRAFT_START__', escape(node.id)));
            }
            window.Alpine.mutateDom(() => {
                if (shell) { retire(shell); window.Alpine.destroyTree(shell); shell.replaceWith(card); }
                else (trigger ? main.querySelector('.workflow-node-starts') : main).append(card);
                window.Alpine.initTree(card);
            });
        }
        // The empty event slot is only the first start. Later starts use the
        // separate canvas button, so the selected trigger replaces this slot.
        const starts = main.querySelector('.workflow-node-starts');
        let addSlot = starts.querySelector('[data-workflow-start-add]');
        if (trigger) {
            if (addSlot) window.Alpine.mutateDom(() => { window.Alpine.destroyTree(addSlot); addSlot.remove(); });
        } else {
            if (!addSlot) {
                const template = templates.find(el => el.dataset.workflowDraftTemplate === 'empty');
                addSlot = template.content.firstElementChild.cloneNode(true);
                window.Alpine.mutateDom(() => { starts.append(addSlot); window.Alpine.initTree(addSlot); });
            }
            if (addSlot !== starts.lastElementChild) window.Alpine.mutateDom(() => starts.append(addSlot));
            window.Alpine.mutateDom(() => { addSlot.dataset.workflowNodeId = 'trigger'; });
        }
        announce('workflow-canvas-after-update', {componentId: wire.$id});
        announce('workflow-connections-updated');
    };
    const draft = new WorkflowEditorDraft(wire, catalog, render, announce, canvas, window.Alpine.reactive);
    let catalogSource = root.querySelector('[data-workflow-draft-catalog]').textContent;
    draft.refreshCatalog = () => {
        const source = root.querySelector('[data-workflow-draft-catalog]').textContent;
        if (source !== catalogSource) { draft.catalog = JSON.parse(source); catalogSource = source; }
    };
    // Canvas geometry keeps its existing API, but pure graph methods are local.
    const localMethods = new Set(['connectWorkflowNodes', 'disconnectWorkflowNodes', 'copyWorkflowNodes', 'pasteWorkflowNodes', 'selectActionType', 'selectReadOperation', 'removeWorkflowAction']);
    draft.adapter = new Proxy(draft.wire, {get(target, key) {
        return localMethods.has(key) ? async (...params) => draft[key](...params) : target[key];
    }});
    const detachTransport = draft.attachTransport(wire);
    const detachMorph = window.Livewire.hook('morph.updating', ({el, toEl, component, skip}) => {
        if (component.id !== wire.$id || el !== root.querySelector('#workflow-canvas')) return;
        // Leave the live card shells, coordinates and Alpine controls in place.
        // Reuse server-selected artwork when it describes this configuration.
        const actions = draft.wire.workflowActions;
        const configs = new Map(flatActions(actions).map(node => ['action:' + node.id, node.config]));
        const incoming = new Map(flatActions(component.canonical.workflowActions).map(node => ['action:' + node.id, node.config]));
        const cards = new Map([...el.querySelectorAll('[data-workflow-node-id]')].map(shell => [shell.dataset.workflowNodeId, shell]));
        for (const shell of toEl.querySelectorAll('[data-workflow-node-id]')) {
            const id = shell.dataset.workflowNodeId;
            if (!configs.has(id) || !equal(configs.get(id), incoming.get(id))) continue;
            const card = cards.get(id)?.querySelector(':scope > [data-workflow-node-card]');
            const appearance = shell.querySelector(':scope > [data-workflow-node-card]');
            if (!card || !appearance) continue;
            card.dataset.nodeFamily = appearance.dataset.nodeFamily;
            card.dataset.nodeTone = appearance.dataset.nodeTone;
            const icon = card.querySelector('.workflow-node-card__icon');
            const updatedIcon = appearance.querySelector('.workflow-node-card__icon');
            if (icon && updatedIcon && !icon.isEqualNode(updatedIcon)) icon.replaceWith(updatedIcon.cloneNode(true));
        }
        skip();
    });
    draft.destroy = () => { detachMorph(); detachTransport(); };
    return draft;
}
