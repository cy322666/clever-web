// Track the request from the click, not from the server response. The modal and
// canvas share this component-scoped state, including validation/network failures.
window.initializeWorkflowNodeLoading = () => {
    if (!window.Livewire?.interceptAction || !window.Alpine || window.Alpine.store('workflowNodeRuns')) return;
    window.Alpine.store('workflowNodeRuns', {pending: {}});
    window.Livewire.interceptAction(({action, onFinish}) => {
        const nodeId = action.name === 'runWorkflowCanvasNode' ? action.params[0]
            : action.name === 'runEditingWorkflowNode' ? (action.component.reactive.editingActionId || 'trigger')
            : action.name === 'runWorkflowManually' ? '__workflow' : null;
        if (!nodeId) return;
        const pending = window.Alpine.store('workflowNodeRuns').pending;
        const componentId = action.component.id;
        pending[componentId] ??= {};
        pending[componentId][nodeId] = (pending[componentId][nodeId] || 0) + 1;
        let finished = false;
        onFinish(() => {
            if (finished) return;
            finished = true;
            if (--pending[componentId][nodeId] <= 0) delete pending[componentId][nodeId];
            if (!Object.keys(pending[componentId]).length) delete pending[componentId];
        });
    });
};
window.workflowNodeIsRunning = (wire, nodeId = null) => {
    const pending = window.Alpine?.store('workflowNodeRuns')?.pending[wire.$id] || {};
    const requested = nodeId === null ? Object.keys(pending).length > 0 : (pending[nodeId] || 0) > 0;
    // Queuing a loop ends the HTTP request, not the execution. Polling owns its end.
    const queued = Boolean(wire.nodePreviewTaskId) && (nodeId === null || wire.nodePreviewProgress?.node_id === nodeId);
    return requested || queued || (nodeId === null && ['pending', 'running'].includes(wire.manualRunStatus));
};
window.workflowManualIsRunning = wire => window.workflowNodeIsRunning(wire, '__workflow') || ['pending', 'running'].includes(wire.manualRunStatus);
document.addEventListener('livewire:init', window.initializeWorkflowNodeLoading);
window.initializeWorkflowNodeLoading();

window.workflowExpressionParts = (value) => String(value ?? '').split(/(\{\{[\s\S]*?\}\})/g).filter(Boolean).map(text => ({text, variable: text.startsWith('{{') && text.endsWith('}}')}));

// Keep native inputs (caret, selection, Livewire bindings) and paint only their text.
if (typeof document.querySelectorAll === 'function') {
    const painted = new Map();
    let queued = false;
    const refresh = () => {
        queued = false;
        for (const [field, state] of painted) {
            if (!field.isConnected) { state.layer.remove(); state.resize?.disconnect(); painted.delete(field); }
        }
        document.querySelectorAll('.workflow-node-settings input:not([type=hidden]):not([type=password]):not([type=search]):not([type=checkbox]):not([type=radio]):not([type=number]), .workflow-node-settings textarea').forEach(field => {
            if (!field.hasAttribute('data-workflow-value-input') && !Array.from(field.attributes).some(a => a.name.startsWith('wire:model'))) return;
            if (Array.from(field.attributes).some(a => a.name.startsWith('wire:model') && a.value.endsWith('.javascript_code'))) return;
            let state = painted.get(field);
            const parts = window.workflowExpressionParts(field.value);
            if (!parts.some(part => part.variable) || !field.offsetWidth) {
                if (field.classList.contains('workflow-expression-painted')) field.classList.remove('workflow-expression-painted');
                if (state) state.layer.hidden = true;
                return;
            }
            if (!state) {
                const layer = document.createElement('div'); layer.className = 'workflow-expression-paint'; layer.setAttribute('aria-hidden', 'true');
                const text = document.createElement('div'); layer.append(text);
                const parent = field.parentElement;
                if (getComputedStyle(parent).position === 'static') parent.style.position = 'relative';
                parent.append(layer);
                state = {layer, text, value: null}; painted.set(field, state);
                field.addEventListener('scroll', () => { layer.scrollTop = field.scrollTop; layer.scrollLeft = field.scrollLeft; });
                if (typeof ResizeObserver !== 'undefined') { state.resize = new ResizeObserver(schedule); state.resize.observe(field); }
            }
            if (state.layer.parentElement !== field.parentElement) {
                if (getComputedStyle(field.parentElement).position === 'static') field.parentElement.style.position = 'relative';
                field.parentElement.append(state.layer);
            }
            state.layer.hidden = false;
            // The native caret and painted text must use identical compact typography.
            if (!field.classList.contains('workflow-expression-painted')) field.classList.add('workflow-expression-painted');
            const style = getComputedStyle(field);
            Object.assign(state.layer.style, {left:field.offsetLeft+'px',top:field.offsetTop+'px',width:(field.clientWidth + parseFloat(style.borderLeftWidth || 0) + parseFloat(style.borderRightWidth || 0))+'px',height:(field.clientHeight + parseFloat(style.borderTopWidth || 0) + parseFloat(style.borderBottomWidth || 0))+'px',font:style.font,letterSpacing:style.letterSpacing,lineHeight:style.lineHeight,border:style.borderWidth+' solid transparent',padding:style.padding});
            state.text.style.whiteSpace = field.tagName === 'TEXTAREA' ? 'pre-wrap' : 'pre';
            state.text.style.overflowWrap = field.tagName === 'TEXTAREA' ? 'break-word' : 'normal';
            if (state.value !== field.value) {
                state.text.replaceChildren(...parts.map(part => { const span = document.createElement('span'); span.textContent = part.text; if (part.variable) span.className = 'workflow-expression-token'; return span; }));
                state.text.append(document.createTextNode('\u200b'));
                state.value = field.value;
            }
            state.layer.scrollTop = field.scrollTop; state.layer.scrollLeft = field.scrollLeft;
        });
    };
    function schedule() { if (!queued) { queued = true; requestAnimationFrame(refresh); } }
    ['input','change','focusin','click','drop','livewire:navigated','alpine:initialized','DOMContentLoaded'].forEach(event => document.addEventListener(event, schedule));
    new MutationObserver(records => {
        if (records.some(record => !record.target.closest?.('.workflow-expression-paint') && (record.type === 'childList' || record.attributeName === 'value' || record.target === document.documentElement || record.target.matches?.('input,textarea')))) schedule();
    }).observe(document.documentElement,{childList:true,subtree:true,attributes:true,attributeFilter:['value','class']});
    schedule();
}

window.workflowSortableList = (path) => ({
    path: path ?? '',
    draggingIndex: null,
    overIndex: null,
    clickSuppressed: false,

    dragPayload(event) {
        const raw = event.dataTransfer?.getData('application/x-workflow-action')
            || event.dataTransfer?.getData('text/plain');

        if (!raw) {
            return null;
        }

        try {
            return JSON.parse(raw);
        } catch {
            return null;
        }
    },

    onDragStart(event, index) {
        this.draggingIndex = index;
        this.overIndex = index;
        this.clickSuppressed = false;

        const payload = JSON.stringify({path: this.path, index});

        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('application/x-workflow-action', payload);
        event.dataTransfer.setData('text/plain', payload);
    },

    onDragOver(event, index) {
        const payload = this.dragPayload(event);

        if (!payload || payload.path !== this.path) {
            return;
        }

        this.overIndex = index;
        event.dataTransfer.dropEffect = 'move';
    },

    onDrop(event, index) {
        const payload = this.dragPayload(event);

        if (!payload || payload.path !== this.path || Number(payload.index) === index) {
            this.resetDragState();

            return;
        }

        this.clickSuppressed = true;
        this.$wire.reorderWorkflowActions(this.path, Number(payload.index), index);
        this.resetDragState();

        window.setTimeout(() => {
            this.clickSuppressed = false;
        }, 120);
    },

    onDragEnd() {
        this.resetDragState();

        window.setTimeout(() => {
            this.clickSuppressed = false;
        }, 120);
    },

    suppressClickAfterDrag(event) {
        if (!this.clickSuppressed) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        event.stopPropagation();
        this.clickSuppressed = false;
    },

    resetDragState() {
        this.draggingIndex = null;
        this.overIndex = null;
    },
});

// The dock opens before the request. Tabs, canvas and library stay interactive;
// only the selected node's real Filament form is fetched by its partial renderer.
window.workflowInspector = () => ({
    opened: false, loading: false, title: '', tab: 'settings', error: '', position: {},
    desired: undefined, processing: false, currentId: null, opener: null, dismissed: false,
    positionInspector() {
        const canvas = document.querySelector('.workflow-workbench__main') || document.querySelector('.workflow-node-editor');
        const top = canvas?.getBoundingClientRect().top ?? 100;
        this.position = {top: `${Math.max(0, top)}px`, bottom: '0'};
    },
    announce() {
        window.dispatchEvent(new CustomEvent('workflow-inspector-state', {detail: {componentId: this.$wire.$id, open: this.opened}}));
    },
    inspectorMounted(element) {
        const changed = this.currentId !== element.dataset.inspectorNode;
        this.currentId = element.dataset.inspectorNode;
        if (!this.processing && (!this.dismissed || changed)) {
            this.dismissed = false;
            this.title = element.dataset.inspectorTitle;
            this.opened = true;
            this.positionInspector();
            this.announce();
        }
    },
    nextTab(direction) {
        const tabs = ['settings', 'input', 'output'];
        this.tab = tabs[(tabs.indexOf(this.tab) + direction + tabs.length) % tabs.length];
        this.$nextTick(() => this.$el.querySelector('[role=tab][aria-selected=true]')?.focus());
    },
    openInspector(detail) {
        if (detail.nodeId === this.currentId && this.opened && !this.processing) return;
        this.opener = document.activeElement;
        this.dismissed = false;
        this.opened = true;
        this.title = detail.name;
        this.error = '';
        this.positionInspector();
        this.announce();
        // Outside clicks only hide the dock. Reuse its mounted draft, even if
        // incomplete, without validation, a request or a flash of loading.
        if (detail.nodeId === this.currentId && !this.processing && this.$wire.mountedActions?.length) return;
        this.desired = detail;
        return this.flushInspector();
    },
    dismissInspector(event) {
        if (!this.opened || this.$el.contains(event.target)) return;
        // Filament widgets may teleport their options or a nested dialog.
        if (event.target.closest?.('.fi-modal, .fi-dropdown-panel, .fi-select-input-options-ctn, [role="listbox"], .tippy-box')) return;
        this.hideInspector();
    },
    hideInspector() {
        this.opened = false;
        this.dismissed = true;
        this.desired = undefined;
        this.announce();
    },
    showValidationErrors() {
        if (!['configureWorkflowAction', 'configureTrigger'].includes(this.$wire.mountedActions?.[0]?.name)) return;
        this.tab = 'settings';
        this.dismissed = false;
        this.opened = true;
        this.positionInspector();
        this.announce();
    },
    closeInspector(save = true) {
        this.desired = {close: true, save};
        return this.flushInspector();
    },
    async flushInspector() {
        if (this.processing) return;
        this.processing = this.loading = true;
        try {
            while (this.desired) {
                const target = this.desired;
                this.desired = undefined;
                if (target.close) {
                    if (this.$wire.mountedActions?.length) {
                        if (target.save) await this.$wire.callMountedAction();
                        else await this.$wire.unmountAction();
                    }
                    if (!this.$wire.mountedActions?.length) {
                        this.opened = false;
                        this.currentId = null;
                        this.opener?.isConnected && this.opener.focus();
                    } else this.tab = 'settings';
                } else {
                    await this.$wire.openWorkflowInspector(target.nodeId);
                    const action = this.$wire.mountedActions?.[0];
                    this.currentId = action?.name === 'configureTrigger' ? this.$wire.editingTriggerNodeId
                        : action?.name === 'configureWorkflowAction' ? 'action:' + this.$wire.editingActionId : null;
                    if (this.currentId !== target.nodeId) {
                        this.desired = undefined;
                        this.tab = 'settings';
                        this.error = 'Проверьте настройки текущей ноды перед переходом.';
                    }
                }
            }
        } catch {
            this.error = 'Не удалось загрузить или применить настройки. Повторите попытку.';
            this.desired = undefined;
        } finally {
            this.processing = this.loading = false;
            this.$nextTick(() => {
                const form = this.$el.querySelector('[data-inspector-node]');
                if (form) this.title = form.dataset.inspectorTitle;
            });
            this.announce();
        }
    },
});

window.workflowNodeLibrary = () => ({
    open: false, mode: 'all', view: 'root', entity: null, query: '', replaceTrigger: false, anchor: null,
    viewport: {width: window.innerWidth, height: window.innerHeight},
    floatingStyle() {
        const width = Math.min(320, this.viewport.width - 24);
        const height = Math.min(470, this.viewport.height - 24);
        const anchor = this.anchor || {x: (this.viewport.width - width) / 2, y: Math.max(100, (this.viewport.height - height) / 2)};
        return {position: 'fixed', left: `${Math.max(12, Math.min(anchor.x, this.viewport.width-width-12))}px`, top: `${Math.max(12, Math.min(anchor.y, this.viewport.height-height-12))}px`, width: `${width}px`, maxHeight: `${height}px`};
    },
    normalize(value) { return String(value ?? '').toLocaleLowerCase('ru-RU').trim(); },
    matches(value) {
        const query = this.normalize(this.query);
        const source = this.normalize(value);
        return query === '' || query.split(/\s+/).every(token => source.includes(token));
    },
    resetView(nextMode = this.mode) {
        this.mode = nextMode;
        this.view = 'root';
        this.entity = null;
        this.query = '';
    },
    openFor(detail = {}) {
        this.open = true;
        this.anchor = detail.anchor || null;
        this.replaceTrigger = Boolean(detail.replace);
        this.resetView(detail.mode || 'all');
        this.$nextTick(() => { if (this.mode !== 'variables') this.$refs.search?.focus(); });
    },
    closeLibrary() {
        this.open = false;
        this.anchor = null;
        window.dispatchEvent(new CustomEvent('workflow-node-library-closed'));
        // Keep the insertion context while browsing categories and searching.
        ['insertConnection', 'insertActionPath', 'insertActionIndex', 'targetPath'].forEach(key => this.$wire.$set(key, null, false));
    },
    goBack() {
        this.query = '';
        if (this.mode === 'action' && this.view === 'variants') {
            this.view = 'amocrm';
            this.entity = null;
        } else if (this.view !== 'root') {
            this.view = 'root';
            this.entity = null;
        } else if (!this.replaceTrigger) this.resetView('all');
    },
});

window.workflowInlineNodeName = (nodeId, initialName) => ({
    renaming: false, nameDraft: '', renameError: '', renamingBusy: false,
    get nodeName() {
        if (nodeId === 'trigger') return this.$wire.trigger?.name || initialName;
        if (nodeId.startsWith('trigger:')) return this.$wire.definition?.additional_triggers?.find(node => node.id === nodeId)?.name || initialName;
        const find = steps => (steps || []).flatMap(step => [step, ...find(step.config?.true_actions), ...find(step.config?.false_actions)]);
        return find(this.$wire.workflowActions).find(node => node.id === nodeId)?.name || initialName;
    },
    startRename() {
        this.nameDraft = this.nodeName;
        this.renameError = '';
        this.renaming = true;
        this.$nextTick(() => { this.$refs.nodeNameInput.focus(); this.$refs.nodeNameInput.select(); });
    },
    async commitRename() {
        if (!this.renaming || this.renamingBusy) return;
        const name = this.nameDraft.trim();
        if (!name || [...name].length > 100) { this.renameError = 'От 1 до 100 символов'; return; }
        if (name === this.nodeName) { this.renaming = false; return; }
        this.renamingBusy = true;
        try {
            await this.$wire.renameWorkflowNode(nodeId, name);
            if (this.nodeName === name) this.renaming = false;
            else this.renameError = 'Это название уже используется или нода удалена.';
        } catch { this.renameError = 'Не удалось переименовать. Попробуйте ещё раз.'; }
        finally { this.renamingBusy = false; }
    },
});

window.workflowWorkbench = () => ({
    inspectorOpen: false,
    get hasUnsavedChanges() {
        const saved = this.$wire?.savedWorkflowEditorState;
        if (!saved) return true;
        const state = {
            name: this.$wire.data?.name ?? '',
            group_name: this.$wire.data?.group_name ?? null,
            definition: {...this.$wire.definition, trigger: this.$wire.trigger, actions: this.$wire.workflowActions},
        };
        // These two fields are dictionaries. PHP serializes an empty dictionary
        // as [], while canvas layout refreshes create {}. They are the same state.
        // Do not coerce node payloads: arrays and objects there mean different things.
        const absoluteLayout = Boolean(saved.definition?.canvas_positions);
        const editorState = value => ({...value, definition: {...value.definition,
            // Absolute card coordinates survive local deletion and later DOM
            // flattening. Relative translations are derived once this is saved.
            canvas_layout: absoluteLayout ? undefined : {...value.definition?.canvas_layout},
            canvas_positions: absoluteLayout ? value.definition?.canvas_positions : undefined,
            canvas_edge_routes: {...value.definition?.canvas_edge_routes},
        }});
        // Ignore object key ordering, but preserve action/branch order and values.
        const stable = value => Array.isArray(value) ? value.map(stable)
            : value && typeof value === 'object' ? Object.fromEntries(Object.keys(value).sort().map(key => [key, stable(value[key])])) : value;
        const mounted = this.$wire.mountedActions?.[0];
        if (['configureWorkflowAction', 'configureTrigger'].includes(mounted?.name) && this.$wire.workflowInspectorInitialData !== null
            && JSON.stringify(stable(mounted.data)) !== JSON.stringify(stable(this.$wire.workflowInspectorInitialData))) return true;
        return JSON.stringify(stable(editorState(state))) !== JSON.stringify(stable(editorState(saved)));
    },

    initWorkbench() {
        this.$nextTick(() => this.syncEditorHeight());
    },

    syncEditorHeight() {
        const page = this.$el.closest('.workflow-editor-page');

        if (page) {
            page.style.setProperty('--workflow-page-top', `${Math.max(0, page.getBoundingClientRect().top)}px`);
        }
    },
});

window.workflowNodeMenu = () => ({
    menuOpen: false,
    toggleMenu() {
        if (this.menuOpen) return this.closeMenu(true);
        window.dispatchEvent(new CustomEvent('workflow-node-menu-open', {detail: this.$el}));
        this.menuOpen = true;
        this.$nextTick(() => this.focusMenuItem(0));
    },
    closeMenu(returnFocus = false) {
        this.menuOpen = false;
        if (returnFocus) this.$refs.more.focus();
    },
    focusMenuItem(index) {
        const items = [...this.$refs.menu.querySelectorAll('[role="menuitem"]')].filter(item => !item.disabled);
        if (items.length) items[(index + items.length) % items.length].focus();
    },
    moveMenuFocus(event, direction) {
        const items = [...this.$refs.menu.querySelectorAll('[role="menuitem"]')].filter(item => !item.disabled);
        this.focusMenuItem(items.indexOf(event.target) + direction);
    },
});

window.workflowNodeCanvas = (layoutKey = 'clever.workflow.layout.v2:draft', initialLayout = null, initialEdgeRoutes = null, initialCoordinates = null) => ({
    layoutKey,
    initialLayout,
    initialEdgeRoutes,
    initialCoordinates,
    positions: {},
    edgeRoutes: {},
    routeDrag: null,
    scale: 1,
    translateX: 0,
    translateY: 0,
    pointerId: null,
    pointerStartX: 0,
    pointerStartY: 0,
    draggingNodeId: null,
    draggingNodeElement: null,
    nodeStartX: 0,
    nodeStartY: 0,
    nodeDragMoved: false,
    suppressClickUntil: 0,
    suppressedNodeId: null,
    refreshFrame: null,
    settleFrame: null,
    mutationObserver: null,
    resizeObserver: null,
    observedGeometry: null,
    observedStage: null,
    releaseMorphHook: null,
    releaseBeforeMorphHook: null,
    morphPositions: null,
    clipboardPositions: null,
    clipboardCopy: null,
    pasting: false,
    executionState: null,
    connecting: null,
    connectionTargetId: null,
    pendingConnections: [],
    nextConnectionId: 0,
    connectionError: '',
    connectionCursor: null,
    connectionDragOrigin: null,
    selectedEdge: null,
    pendingEdgeDrag: null,
    suppressEdgeClickUntil: 0,
    selectedNodeIds: [],
    selectionBox: null,
    selectionOrigin: null,
    groupDragOrigins: {},
    pendingInsertion: null,
    pendingDropPosition: null,
    initialized: false,

    canvasActions(steps = this.$wire?.workflowActions || []) {
        return steps.flatMap(step => [step, ...this.canvasActions(step.config?.true_actions || []), ...this.canvasActions(step.config?.false_actions || [])]);
    },

    canvasAction(id) {
        return this.canvasActions().find(step => String(step.id) === String(id));
    },

    toggleCanvasAction(id) {
        const actions = JSON.parse(JSON.stringify(this.$wire.workflowActions));
        const action = this.canvasActions(actions).find(step => String(step.id) === String(id));
        if (!action) return;
        action.disabled = !action.disabled;
        this.$wire.$set('workflowActions', actions, false);
        this.$wire.$set('definition.actions', actions, false);
    },

    removeCanvasAction(id) {
        const action = this.canvasAction(id);
        if (!action) return;
        const nodeId = 'action:' + id;
        const controls = this.$refs.stage?.querySelectorAll('[data-workflow-edge-source]') || [];
        const edges = this.$wire.definition.connections ?? Array.from(controls)
            .filter(control => control.dataset.workflowEdgeTarget)
            .map(control => ({sourceId:control.dataset.workflowEdgeSource, sourcePort:control.dataset.workflowEdgePort, targetId:control.dataset.workflowEdgeTarget}));
        const remaining = edges.filter(edge => edge.sourceId !== nodeId && edge.targetId !== nodeId);
        // Match server deletion: only an ordinary output can be bridged. Keep
        // condition children as detached nodes, never execute both arms at once.
        if (!['condition', 'control-condition'].includes(action.type)) {
            edges.filter(edge => edge.targetId === nodeId).forEach(incoming => {
                edges.filter(edge => edge.sourceId === nodeId && edge.sourcePort === 'output').forEach(outgoing => {
                    const edge = {...incoming, targetId:outgoing.targetId};
                    if (edge.sourceId !== edge.targetId && !remaining.some(item => this.edgeRouteKey(item) === this.edgeRouteKey(edge))) remaining.push(edge);
                });
            });
        }
        const actions = this.canvasActions().filter(step => String(step.id) !== String(id)).map(step => {
            const copy = JSON.parse(JSON.stringify(step));
            delete copy.config?.true_actions;
            delete copy.config?.false_actions;
            return copy;
        });
        this.$wire.$set('workflowActions', actions, false);
        this.$wire.$set('definition.actions', actions, false);
        this.$wire.$set('definition.connections', remaining, false);
        delete this.positions[nodeId];
        this.edgeRoutes = Object.fromEntries(Object.entries(this.edgeRoutes).filter(([key]) => remaining.some(edge => this.edgeRouteKey(edge) === key)));
        this.selectedNodeIds = this.selectedNodeIds.filter(selected => selected !== nodeId);
        this.cancelConnection();
        this.saveNodeLayout();
        this.$nextTick(() => this.scheduleGraphRefreshAfterPaint());
    },

    canvasEdges(initialEdges, startIds) {
        let connections = this.$wire?.definition?.connections;
        if (!Array.isArray(connections) && !this.pendingConnections.length) return initialEdges.filter(edge => !edge.branch);
        connections = Array.isArray(connections) ? [...connections] : initialEdges.filter(edge => edge.targetId && !edge.branch);
        for (const pending of this.pendingConnections) {
            connections = connections.filter(edge => !(edge.sourceId === pending.sourceId && edge.sourcePort === pending.sourcePort && [pending.targetId, pending.replaceTarget].includes(edge.targetId)));
            connections.push({sourceId:pending.sourceId, sourcePort:pending.sourcePort, targetId:pending.targetId});
        }
        const nodes = Object.fromEntries(this.canvasActions().map(step => ['action:' + step.id, step]));
        const returns = new Set();
        const isLoop = id => nodes[id]?.type === 'workflow_loop';
        const reach = (pending, edges, stop = null, opaqueLoops = false) => {
            const seen = new Set();
            while (pending.length) {
                const id = pending.pop();
                if (id === stop || seen.has(id)) continue;
                seen.add(id);
                edges.filter(edge => edge.sourceId === id && (!opaqueLoops || !isLoop(id) || edge.sourcePort === 'done')).forEach(edge => pending.push(edge.targetId));
            }
            return seen;
        };
        Object.keys(nodes).filter(isLoop).forEach(id => {
            const body = reach(connections.filter(edge => edge.sourceId === id && edge.sourcePort === 'each').map(edge => edge.targetId), connections, id, true);
            connections.forEach((edge, index) => {
                if (edge.targetId === id && body.has(edge.sourceId) && (!isLoop(edge.sourceId) || edge.sourcePort === 'done')) returns.add(index);
            });
        });
        const forward = connections.filter((_, index) => !returns.has(index));
        const edges = connections.map((edge, index) => {
            let loopReturn = returns.has(index);
            if (!loopReturn && isLoop(edge.sourceId) && edge.sourcePort === 'done') {
                const next = reach([edge.targetId], forward.filter(item => item !== edge));
                loopReturn = next.has(edge.sourceId) || connections.some((item, i) => returns.has(i) && item.targetId === edge.sourceId && next.has(item.sourceId))
                    || connections.some(item => item.sourceId === edge.sourceId && item.sourcePort === 'each' && item.targetId === edge.targetId);
            }
            return {...edge, loopReturn};
        });
        const allNodes = {...Object.fromEntries(startIds.map(id => [id, {type:'trigger'}])), ...nodes};
        Object.entries(allNodes).forEach(([id, node]) => {
            const ports = node.type === 'workflow_loop' ? ['each','done'] : ['condition','control-condition'].includes(node.type) ? ['yes','no'] : ['output'];
            ports.forEach(port => {
                if (!edges.some(edge => edge.sourceId === id && edge.sourcePort === port)) edges.push({sourceId:id,sourcePort:port,targetId:null});
            });
        });
        return edges;
    },

    // Placement uses a hidden 10-unit grid, independent of the static backdrop.
    gridSpacing: 20,
    snapCoordinate(value) {
        const step = this.gridSpacing / 2;
        return Math.round(value / step) * step;
    },

    gridStyle() {
        return 'background-size:20px 20px;background-position:0px 0px;';
    },

    cardPositions() {
        const rect = this.$refs.stage?.getBoundingClientRect();
        if (!rect) return {};
        return Object.fromEntries(this.nodeElements().map(node => {
            const card = node.querySelector('[data-workflow-node-card]') || node;
            const box = card.getBoundingClientRect();
            // A card's hover/focus lift is decoration, not a saved coordinate.
            const matrix = card !== node ? window.getComputedStyle?.(card).transform?.match(/^matrix\(([^)]+)\)$/)?.[1].split(',').map(Number) : null;
            return [node.dataset.workflowNodeId, {x:(box.left-rect.left)/this.scale-(matrix?.[4] || 0),y:(box.top-rect.top)/this.scale-(matrix?.[5] || 0)}];
        }));
    },

    restoreCardPositions(desired) {
        const actual = this.cardPositions();
        let changed = false;
        Object.entries(desired || {}).forEach(([id, point]) => {
            if (!actual[id] || !Number.isFinite(point?.x) || !Number.isFinite(point?.y)) return;
            const dx = point.x - actual[id].x, dy = point.y - actual[id].y;
            if (Math.abs(dx) < 0.01 && Math.abs(dy) < 0.01) return;
            const offset = this.positions[id] || {x:0,y:0};
            this.positions[id] = {x:offset.x+dx,y:offset.y+dy};
            changed = true;
        });
        if (changed) {
            this.applyNodePositions();
            this.saveNodeLayout();
        }
        return changed;
    },

    async clipboardShortcut(event) {
        if (!(event.ctrlKey || event.metaKey) || event.altKey || event.shiftKey || event.repeat) return;
        if (event.target?.closest?.('input, textarea, select, [contenteditable="true"], [role="dialog"]')) return;
        if (!this.$el?.isConnected || this.$el.getClientRects?.().length === 0) return;
        const key = (event.code || event.key).toLowerCase();
        if ((key === 'keyc' || key === 'c') && this.selectedNodeIds.length) {
            event.preventDefault();
            const ids = [...this.selectedNodeIds];
            const points = this.cardPositions();
            this.clipboardCopy = this.$wire.copyWorkflowNodes(ids).then(copied => {
                if (copied) {
                    this.clipboardPositions = Object.fromEntries(ids.filter(id => points[id]).map(id => [id, points[id]]));
                }
            });
            await this.clipboardCopy;
        } else if ((key === 'keyv' || key === 'v') && (this.clipboardPositions || this.clipboardCopy) && !this.pasting) {
            event.preventDefault();
            this.pasting = true;
            try {
                await this.clipboardCopy;
                if (!this.clipboardPositions) return;
                const map = await this.$wire.pasteWorkflowNodes();
                if (!Object.keys(map || {}).length) return;
                await this.$nextTick();
                await new Promise(resolve => window.requestAnimationFrame(resolve));
                this.applyNodePositions();
                this.centerCopiedNodes(map, this.clipboardPositions);
            } finally { this.pasting = false; }
        }
    },

    placeCopiedNodes(detail) {
        this.$nextTick(() => window.requestAnimationFrame(() => {
            this.centerCopiedNodes(detail.map, this.cardPositions());
        }));
    },

    centerCopiedNodes(map, sourcePositions) {
        const points = Object.fromEntries(Object.entries(map || {}).filter(([id]) => sourcePositions[id])
            .map(([oldId, newId]) => [newId, sourcePositions[oldId]]));
        this.centerNodeGroup(points);
    },

    centerNodeGroup(points) {
        const viewport = this.$refs.viewport?.getBoundingClientRect();
        const stage = this.$refs.stage?.getBoundingClientRect();
        const cards = this.nodeElements().filter(node => points[node.dataset.workflowNodeId]).map(node => ({
            id:node.dataset.workflowNodeId, point:points[node.dataset.workflowNodeId],
            box:node.querySelector('[data-workflow-node-card]')?.getBoundingClientRect(),
        })).filter(card => card.box);
        if (!viewport || !stage || !cards.length) return;
        const left = Math.min(...cards.map(c => c.point.x)), top = Math.min(...cards.map(c => c.point.y));
        const right = Math.max(...cards.map(c => c.point.x+c.box.width/this.scale));
        const bottom = Math.max(...cards.map(c => c.point.y+c.box.height/this.scale));
        const dx = this.snapCoordinate((viewport.left+viewport.width/2-stage.left)/this.scale - (right-left)/2) - left;
        const dy = this.snapCoordinate((viewport.top+viewport.height/2-stage.top)/this.scale - (bottom-top)/2) - top;
        this.restoreCardPositions(Object.fromEntries(cards.map(c => [c.id, {x:c.point.x+dx,y:c.point.y+dy}])));
        this.selectedNodeIds = cards.map(c => c.id);
        this.applyNodeSelection();
        this.scheduleGraphRefreshAfterPaint();
    },

    captureInsertion(sourceId, targetId, dropPosition = null) {
        const stage = this.$refs.stage;
        if (!stage) return;
        const nodes = this.cardPositions();
        const controls = Array.from(stage.querySelectorAll('[data-workflow-edge-target]'));
        const loopReturn = controls.some(e => e.dataset.workflowEdgeSource === sourceId && e.dataset.workflowEdgeTarget === targetId && e.dataset.workflowLoopReturn === 'true');
        const edges = controls.filter(e => e.dataset.workflowLoopReturn !== 'true').map(e => ({source:e.dataset.workflowEdgeSource,target:e.dataset.workflowEdgeTarget}));
        this.pendingInsertion = {sourceId,targetId,nodes,edges,dropPosition,loopReturn};
    },

    placeInsertedNode(detail) {
        const before = this.pendingInsertion;
        this.pendingInsertion = null;
        if (!before || before.sourceId !== detail.sourceId || before.targetId !== detail.targetId) return;
        this.$nextTick(() => window.requestAnimationFrame(() => {
            const source = before.nodes[detail.sourceId];
            if (!source) return;
            const desired = {...before.nodes};
            const inserted = before.loopReturn
                ? {x:(source.x + (before.nodes[detail.targetId]?.x ?? source.x)) / 2,y:Math.max(...Object.values(desired).map(p=>p.y))+180}
                : {x:source.x+192,y:before.nodes[detail.targetId]?.y ?? source.y+(['yes', 'done'].includes(detail.sourcePort) ? -180 : ['no', 'each'].includes(detail.sourcePort) ? 180 : 0)};
            if (before.dropPosition) {
                const node = this.nodeElements().find(node => node.dataset.workflowNodeId === detail.nodeId);
                const box = node?.querySelector('[data-workflow-node-card]')?.getBoundingClientRect();
                inserted.x = before.dropPosition.x - (box?.width ?? 96 * this.scale) / this.scale / 2;
                inserted.y = before.dropPosition.y - (box?.height ?? 96 * this.scale) / this.scale / 2;
            }
            const downstream = new Set();
            const queue = detail.targetId && !before.loopReturn ? [detail.targetId] : [];
            while (queue.length) {
                const id = queue.shift();
                if (downstream.has(id) || id === detail.sourceId) continue;
                downstream.add(id);
                before.edges.filter(e=>e.source===id).forEach(e=>queue.push(e.target));
            }
            // Keep unrelated nodes where the user put them; make room downstream.
            const target = desired[detail.targetId];
            const shift = target && !before.dropPosition ? Math.max(0, inserted.x+192-target.x) : 0;
            downstream.forEach(id => { if(desired[id]) desired[id] = {...desired[id],x:desired[id].x+shift}; });
            while (!before.dropPosition && Object.values(desired).some(p=>Math.abs(p.x-inserted.x)<144 && Math.abs(p.y-inserted.y)<144)) inserted.y += 180;
            inserted.x = this.snapCoordinate(inserted.x);
            inserted.y = this.snapCoordinate(inserted.y);
            desired[detail.nodeId] = inserted;
            this.restoreCardPositions(desired);
            this.scheduleGraphRefreshAfterPaint();
        }));
    },

    placeCreatedNode(detail) {
        this.pendingInsertion = null;
        const point = this.pendingDropPosition;
        this.pendingDropPosition = null;
        this.$nextTick(() => window.requestAnimationFrame(() => {
            if (!point) return this.centerNodeGroup({[detail.nodeId]:{x:0,y:0}});
            const card = this.nodeElements().find(node => node.dataset.workflowNodeId === detail.nodeId)?.querySelector('[data-workflow-node-card]')?.getBoundingClientRect();
            this.restoreCardPositions({...this.cardPositions(), [detail.nodeId]: {
                x: this.snapCoordinate(point.x - (card?.width ?? 96 * this.scale) / this.scale / 2),
                y: this.snapCoordinate(point.y - (card?.height ?? 96 * this.scale) / this.scale / 2),
            }});
            this.scheduleGraphRefreshAfterPaint();
        }));
    },

    openCanvasPalette(event) {
        if (event.target.closest('button, input, textarea, select, a, [data-workflow-node-card], .workflow-connection-menu, .workflow-node-edge')) return;
        const stage = this.$refs.stage?.getBoundingClientRect();
        if (!stage) return;
        event.preventDefault();
        this.pendingInsertion = null;
        this.pendingDropPosition = {x: (event.clientX - stage.left) / this.scale, y: (event.clientY - stage.top) / this.scale};
        this.preparePalette(null, {x: event.clientX, y: event.clientY});
    },
    preparePalette(connection, anchor = null) {
        // Keep the draft on the client; browsing and opening the menu do not
        // round-trip or morph the canvas. The insertion request validates it.
        this.$wire.$set('insertConnection', connection, false);
        ['insertActionPath', 'insertActionIndex', 'targetPath'].forEach(key => this.$wire.$set(key, null, false));
        window.dispatchEvent(new CustomEvent('workflow-node-library-open', {detail: {mode: 'all', anchor}}));
    },

    selectionStyle() {
        const b = this.selectionBox;
        return b ? `left:${b.x}px;top:${b.y}px;width:${b.width}px;height:${b.height}px` : '';
    },

    clearNodeSelection() {
        this.selectedNodeIds = [];
        this.selectionBox = null;
        this.selectionOrigin = null;
        this.applyNodeSelection();
    },

    applyNodeSelection() {
        this.nodeElements().forEach(node => {
            const selected = this.selectedNodeIds.includes(node.dataset.workflowNodeId);
            const card = node.querySelector('[data-workflow-node-card]');
            if (card && card.classList.contains('workflow-node-card--selected') !== selected) card.classList.toggle('workflow-node-card--selected', selected);
        });
    },

    startEdgeControlDrag(sourceId, sourcePort, targetId, event) {
        if (event.button !== 0) return;
        event.preventDefault();
        this.$refs.viewport?.setPointerCapture?.(event.pointerId);
        this.pendingEdgeDrag = {sourceId, sourcePort, targetId, pointerId: event.pointerId, x: event.clientX, y: event.clientY, moved: false};
    },
    openEdgePalette(sourceId, sourcePort, targetId) {
        if (Date.now() <= this.suppressEdgeClickUntil) return;
        this.captureInsertion(sourceId, targetId);
        this.preparePalette({sourceId, sourcePort, targetId});
    },
    openPaletteAtDrop(event) {
        const viewport = this.$refs.viewport?.getBoundingClientRect?.();
        const stage = this.$refs.stage?.getBoundingClientRect?.();
        if (!viewport || !stage || event.clientX < viewport.left || event.clientX > viewport.right
            || event.clientY < viewport.top || event.clientY > viewport.bottom) return false;
        const hit = document.elementFromPoint?.(event.clientX, event.clientY);
        if (hit?.closest('button, input, select, textarea, a, [data-workflow-node-card], .workflow-connection-menu')) return false;
        const connection = this.connecting;
        // Releasing a new branch does not replace any existing outgoing edges.
        // When reconnecting an existing edge, insertion keeps its old target.
        this.captureInsertion(connection.sourceId, connection.replaceTarget ?? null, {
            x: (event.clientX - stage.left) / this.scale,
            y: (event.clientY - stage.top) / this.scale,
        });
        this.cancelConnection();
        this.preparePalette({sourceId: connection.sourceId, sourcePort: connection.sourcePort, targetId: connection.replaceTarget ?? null}, {x: event.clientX, y: event.clientY});
        return true;
    },
    connectionTargetAt(event) {
        const {clientX: x, clientY: y} = event;
        const valid = id => id?.startsWith('action:') && id !== this.connecting?.sourceId && id !== this.connecting?.replaceTarget;
        // A drawn edge/control can be above the input under the pointer. Inspect
        // the whole hit stack, not just the SVG path returned by elementFromPoint.
        const elements = document.elementsFromPoint?.(x, y) || [document.elementFromPoint?.(x, y)];
        for (const element of elements) {
            if (element?.closest('.workflow-node-card__tools, .workflow-node-port--output')) return null;
            const card = element?.closest('[data-workflow-node-card]');
            if (!card || !this.$refs.stage?.contains(card)) continue;
            const id = card.closest('[data-workflow-node-id]')?.dataset.workflowNodeId;
            if (valid(id)) return id;
        }
        // Keep the input usable when zoomed out: a 10px port must not require
        // pixel-perfect release. Only inputs get tolerance, not node wrappers.
        const viewport = this.$refs.viewport?.getBoundingClientRect?.();
        if (viewport && (x < viewport.left || x > viewport.right || y < viewport.top || y > viewport.bottom)) return null;
        const candidates = this.nodeElements().filter(node => valid(node.dataset.workflowNodeId)).map(node => {
            const box = node.querySelector('.workflow-node-port--input')?.getBoundingClientRect?.();
            return {id: node.dataset.workflowNodeId, distance: box ? Math.hypot(x - (box.left + box.width / 2), y - (box.top + box.height / 2)) : Infinity};
        }).filter(node => node.distance <= 14).sort((a, b) => a.distance - b.distance);
        return candidates[0]?.id || null;
    },

    startConnection(sourceId, sourcePort, event) {
        if (event.button !== undefined && event.button !== 0) return;
        event.preventDefault();
        if (event.pointerId !== undefined) this.$refs.viewport?.setPointerCapture?.(event.pointerId);
        this.connecting = {sourceId, sourcePort, replaceTarget: null};
        this.connectionError = '';
        this.connectionTargetId = null;
        this.connectionDragOrigin = Number.isFinite(event.clientX) ? {x: event.clientX, y: event.clientY} : null;
        this.selectedEdge = null;
        this.connectionCursor = Number.isFinite(event.clientX) ? {x: event.clientX, y: event.clientY} : null;
        this.scheduleGraphRefresh();
    },
    finishConnection(targetId, event) {
        if (!this.connecting) return;
        event.preventDefault();
        if (this.pendingEdgeDrag?.moved) this.suppressEdgeClickUntil = Date.now() + 350;
        this.suppressedNodeId = targetId;
        this.suppressClickUntil = Date.now() + 350;
        this.completeConnection(targetId);
    },
    async completeConnection(targetId) {
        if (!this.connecting) return;
        const connection = {...this.connecting, targetId, pendingId: ++this.nextConnectionId};
        this.cancelConnection();
        this.pendingConnections.push(connection);
        this.$nextTick(() => this.scheduleGraphRefreshAfterPaint());
        try {
            await this.$wire.connectWorkflowNodes(connection.sourceId, connection.sourcePort, targetId, connection.replaceTarget);
        } catch {
            this.connectionError = 'Не удалось сохранить связь. Повторите соединение.';
        } finally {
            this.pendingConnections = this.pendingConnections.filter(pending => pending.pendingId !== connection.pendingId);
            this.$nextTick(() => this.scheduleGraphRefreshAfterPaint());
        }
    },
    cancelConnection() { this.cancelEdgeRouteDrag(); this.connecting = null; this.connectionTargetId = null; this.connectionError = ''; this.pendingEdgeDrag = null; this.connectionCursor = null; this.connectionDragOrigin = null; this.selectedEdge = null; this.scheduleGraphRefresh(); },
    reconnectSelectedEdge(event = {}) { if (this.selectedEdge) this.beginEdgeReconnect(this.selectedEdge, event); },
    beginEdgeReconnect(edge, event = {}) {
        event.preventDefault?.();
        if (event.pointerId !== undefined) this.$refs.viewport?.setPointerCapture?.(event.pointerId);
        this.connecting = {...edge, replaceTarget: edge.targetId};
        this.connectionDragOrigin = Number.isFinite(event.clientX) ? {x: event.clientX, y: event.clientY} : null;
        this.selectedEdge = edge;
        this.connectionCursor = Number.isFinite(event.clientX) ? {x: event.clientX, y: event.clientY} : null;
        this.scheduleGraphRefresh();
    },
    startIncomingConnection(targetId, event) {
        if (this.connecting || (event.button !== undefined && event.button !== 0)) return;
        const controls = this.$refs.stage?.querySelector?.('.workflow-node-edge-controls') || this.$refs.edgeControls;
        const edges = Array.from(controls?.querySelectorAll('[data-workflow-edge-target]') || [])
            .filter(el => el.dataset.workflowEdgeTarget === targetId);
        if (edges.length !== 1) return;
        const data = edges[0].dataset;
        this.beginEdgeReconnect({sourceId: data.workflowEdgeSource, sourcePort: data.workflowEdgePort, targetId}, event);
    },
    async removeSelectedEdge() {
        if (!this.selectedEdge) return;
        const edge = this.selectedEdge;
        this.cancelConnection();
        await this.$wire.disconnectWorkflowNodes(edge.sourceId, edge.sourcePort, edge.targetId);
        delete this.edgeRoutes[this.edgeRouteKey(edge)];
        this.saveNodeLayout();
        this.scheduleGraphRefreshAfterPaint();
    },
    edgeRouteKey(edge) {
        return JSON.stringify([edge.sourceId, edge.sourcePort || 'output', edge.targetId]);
    },
    normalizeEdgeRoutes(routes) {
        return Object.fromEntries(Object.entries(routes || {}).slice(0, 2000).flatMap(([key, route]) => {
            try {
                const ids = JSON.parse(key);
                if (!Array.isArray(ids) || ids.length !== 3 || !ids.every(id => typeof id === 'string')
                    || !/^(trigger(?::[\w-]+)?|action:[\w-]+)$/.test(ids[0])
                    || !/^(output|yes|no|each|done)$/.test(ids[1]) || !/^action:[\w-]+$/.test(ids[2])) return [];
                const fields = route?.kind === 'loop' ? ['exitX', 'entryX', 'y'] : route?.kind === 'curve' ? ['x', 'y'] : [];
                if (!fields.length || !fields.every(field => Number.isFinite(route[field]) && Math.abs(route[field]) <= 100000)) return [];
                return [[JSON.stringify(ids), {kind:route.kind, ...Object.fromEntries(fields.map(field => [field, route[field]]))}]];
            } catch { return []; }
        }));
    },
    startEdgeRouteDrag(edge, route, event, part = null) {
        if (event.button !== 0 || this.connecting) return;
        event.preventDefault();
        event.stopPropagation?.();
        const key = this.edgeRouteKey(edge);
        const bounds = this.$refs.stage?.getBoundingClientRect?.();
        if (!part && route.kind === 'loop' && bounds) {
            const x = (event.clientX-bounds.left)/this.scale, y = (event.clientY-bounds.top)/this.scale;
            part = [['lane',Math.abs(y-route.y)],['exit',Math.abs(x-route.exitX)],['entry',Math.abs(x-route.entryX)]]
                .sort((a,b)=>a[1]-b[1])[0][0];
        }
        this.selectedEdge = edge;
        this.routeDrag = {key, route:{...route}, previous:this.edgeRoutes[key] ? {...this.edgeRoutes[key]} : null,
            part:part || 'lane', x:event.clientX, y:event.clientY, pointerId:event.pointerId, moved:false};
        this.$refs.viewport?.setPointerCapture?.(event.pointerId);
        this.scheduleGraphRefresh();
    },
    cancelEdgeRouteDrag() {
        const drag = this.routeDrag;
        if (!drag) return;
        if (drag.previous) this.edgeRoutes[drag.key] = drag.previous;
        else delete this.edgeRoutes[drag.key];
        this.routeDrag = null;
        this.$refs.viewport?.releasePointerCapture?.(drag.pointerId);
        this.scheduleGraphRefresh();
    },
    resetSelectedEdgeRoute() {
        if (!this.selectedEdge) return;
        delete this.edgeRoutes[this.edgeRouteKey(this.selectedEdge)];
        this.saveNodeLayout();
        this.scheduleGraphRefresh();
    },
    edgeControlButtons(add) {
        const remove = add?.nextElementSibling;
        return [add, remove?.hasAttribute?.('data-workflow-edge-delete') ? remove : null].filter(Boolean);
    },
    showEdgeControls(add) {
        if (!add) return;
        window.clearTimeout(add._workflowEdgeHideTimer);
        this.edgeControlButtons(add).forEach(button => button.setAttribute('data-workflow-edge-visible', 'true'));
    },
    hideEdgeControls(add) {
        if (!add) return;
        window.clearTimeout(add._workflowEdgeHideTimer);
        add._workflowEdgeHideTimer = window.setTimeout(() => {
            const buttons = this.edgeControlButtons(add);
            if (buttons.some(button => button.matches?.(':hover') || button.matches?.(':focus-visible'))) return;
            buttons.forEach(button => button.removeAttribute('data-workflow-edge-visible'));
        }, 120);
    },

    initializeCanvas() {
        if (this.initialized) return;
        this.initialized = true;
        this.restoreNodeLayout();
        this.applyNodePositions();
        this.restoreCardPositions(this.initialCoordinates || this.$wire?.definition?.canvas_positions);
        this.observeCanvas();
        this.releaseBeforeMorphHook = window.Livewire?.hook('morph', ({el}) => {
            if (el === this.$el || el?.contains(this.$el)) this.morphPositions = this.cardPositions();
        });
        this.releaseMorphHook = window.Livewire?.hook('morphed', ({el}) => {
            if (el === this.$el || el?.contains(this.$el)) {
                const before = this.morphPositions;
                this.morphPositions = null;
                this.$nextTick(() => {
                    if (this.observedStage !== this.$refs.stage) this.observeCanvas();
                    this.applyNodePositions();
                    // Flex/tree bases change when nodes are inserted, removed or
                    // flattened. Retain card coordinates, not stale translations.
                    this.restoreCardPositions(before);
                    this.observeNodeGeometry();
                    this.scheduleGraphRefreshAfterPaint();
                });
            }
        });
        this.scheduleGraphRefresh();
        if (!this.restoreViewport()) this.centerView();
        document.fonts?.ready?.then(() => {
            if (this.$el?.isConnected) this.scheduleGraphRefreshAfterPaint();
        });
    },

    destroy() {
        this.saveViewport();
        this.mutationObserver?.disconnect();
        this.resizeObserver?.disconnect();
        this.releaseMorphHook?.();
        this.releaseBeforeMorphHook?.();

        if (this.refreshFrame !== null) {
            window.cancelAnimationFrame(this.refreshFrame);
        }

        if (this.settleFrame !== null) {
            window.cancelAnimationFrame(this.settleFrame);
        }

    },

    restoreNodeLayout() {
        // The saved workflow is authoritative. Browser-only positions are a
        // migration fallback for older workflows that have no saved layout yet.
        let nodes = this.initialLayout, routes = this.initialEdgeRoutes;
        try {
            const stored = JSON.parse(window.localStorage.getItem(this.layoutKey) || '{}');
            if (nodes === null) nodes = stored?.version === 1 ? stored.nodes : {};
            if (routes === null) routes = stored?.version === 1 ? stored.edges : {};
        } catch { /* Use the saved layout when browser storage is unavailable. */ }
        this.edgeRoutes = this.normalizeEdgeRoutes(routes);
        this.positions = Object.fromEntries(Object.entries(nodes || {}).flatMap(([nodeId, position]) => {
                const x = Number(position?.x);
                const y = Number(position?.y);
                return Number.isFinite(x) && Number.isFinite(y)
                    ? [[nodeId, {x, y}]]
                    : [];
        }));
    },

    saveNodeLayout() {
        const activeNodeIds = new Set(this.nodeElements().map(node => node.dataset.workflowNodeId));
        const nodes = Object.fromEntries(Object.entries(this.positions).filter(([nodeId]) => activeNodeIds.has(nodeId)));
        // Defer the network request, not the state update, so an immediate Save
        // includes the final drag position and the dirty indicator updates now.
        this.edgeRoutes = this.normalizeEdgeRoutes(this.edgeRoutes);
        if (this.$wire?.definition) {
            this.$wire.$set('definition.canvas_layout', nodes, false);
            this.$wire.$set('definition.canvas_edge_routes', this.edgeRoutes, false);
            const points = Object.fromEntries(Object.entries(this.cardPositions()).map(([id, point]) => [id, {
                x:Math.round(point.x * 1000) / 1000, y:Math.round(point.y * 1000) / 1000,
            }]));
            this.$wire.$set('definition.canvas_positions', points, false);
        }
        try {
            window.localStorage.setItem(this.layoutKey, JSON.stringify({version: 1, nodes, edges:this.edgeRoutes}));
        } catch { /* Server saving still works when browser storage is unavailable. */ }
        this.positions = nodes;
    },

    resetNodeLayout() {
        const controls = this.$refs.stage?.querySelector('.workflow-node-edge-controls') || this.$refs.edgeControls;
        const edges = Array.from(controls?.querySelectorAll('[data-workflow-edge-target]') || []).map(el => ({
            source: el.dataset.workflowEdgeSource, target: el.dataset.workflowEdgeTarget, port: el.dataset.workflowEdgePort,
            loopReturn: el.dataset.workflowLoopReturn === 'true',
        }));
        const desired = this.graphLayout(this.nodeElements().map(node => node.dataset.workflowNodeId), edges);
        if (!desired) return; // Never destroy a saved layout for an invalid cyclic graph.
        this.edgeRoutes = {};
        this.restoreCardPositions(desired);
        this.saveNodeLayout();
        this.scheduleGraphRefreshAfterPaint();
        this.$nextTick(() => this.fitView());
    },

    graphLayout(ids, edges) {
        const nodes = new Map(ids.map(id => [id, {id, incoming: [], outgoing: [], rank: 0, children: []}]));
        const seen = new Set();
        edges.forEach(edge => {
            if (edge.loopReturn) return;
            const key = JSON.stringify([edge.source, edge.target]);
            if (!nodes.has(edge.source) || !nodes.has(edge.target) || seen.has(key)) return;
            seen.add(key);
            nodes.get(edge.source).outgoing.push(edge);
            nodes.get(edge.target).incoming.push(edge);
        });
        const pending = new Map([...nodes].map(([id, node]) => [id, node.incoming.length]));
        const queue = [...nodes.values()].filter(node => !node.incoming.length);
        const ordered = [];
        for (let index = 0; index < queue.length; index++) {
            const node = queue[index];
            ordered.push(node);
            node.outgoing.forEach(edge => {
                const target = nodes.get(edge.target);
                target.rank = Math.max(target.rank, node.rank + 1);
                pending.set(target.id, pending.get(target.id) - 1);
                if (!pending.get(target.id)) queue.push(target);
            });
        }
        if (ordered.length !== nodes.size) return null;
        // A shared continuation has one layout parent, but retains all real edges.
        ordered.forEach(node => {
            const parent = [...node.incoming].sort((a, b) => nodes.get(b.source).rank - nodes.get(a.source).rank)[0];
            if (parent) nodes.get(parent.source).children.push({node, port: parent.port});
        });
        const portOrder = {yes: 0, done: 0, output: 1, no: 2, each: 2};
        nodes.forEach(node => node.children.sort((a, b) => (portOrder[a.port] ?? 1) - (portOrder[b.port] ?? 1)));
        // Post-order leaf lanes keep both short and nested branches apart.
        let lane = 0;
        const points = {};
        const stack = [...nodes.values()].filter(node => !node.incoming.length).reverse().map(node => ({node, visited: false}));
        while (stack.length) {
            const {node, visited} = stack.pop();
            if (!visited && node.children.length) {
                stack.push({node, visited: true});
                [...node.children].reverse().forEach(child => stack.push({node: child.node, visited: false}));
                continue;
            }
            const y = node.children.length
                ? (points[node.children[0].node.id].y + points[node.children.at(-1).node.id].y) / 2
                : 48 + lane++ * 208;
            points[node.id] = {x: 48 + node.rank * 248, y};
        }
        return points;
    },

    nodeElements() {
        const ids = Array.isArray(this.$wire?.workflowActions)
            ? new Set(this.canvasActions().map(step => 'action:' + step.id)) : null;
        return Array.from(this.$refs.stage?.querySelectorAll('[data-workflow-node-id]') || [])
            .filter(node => !ids || !node.dataset.workflowNodeId.startsWith('action:') || ids.has(node.dataset.workflowNodeId));
    },

    nodeStyle(nodeId) {
        const position = this.positions[nodeId] || {x: 0, y: 0};

        return `transform: translate3d(${position.x}px, ${position.y}px, 0);`;
    },

    applyNodePositions() {
        this.nodeElements().forEach((node) => {
            const nodeId = node.dataset.workflowNodeId;
            if (!this.positions[nodeId] && node.dataset.workflowDetached === 'true') {
                this.positions[nodeId] = {x: 0, y: 180};
            }
            const position = this.positions[nodeId] || {x: 0, y: 0};

            this.applyNodePosition(node, position);
        });
        this.applyExecutionState();
        this.applyNodeSelection();
    },

    setExecutionState(state) {
        this.executionState = state;
        this.applyExecutionState();
        this.scheduleGraphRefresh();
    },

    edgeCompleted(edge, active) {
        if (!edge?.targetId) return false;
        if (active !== undefined) return active === 'true';
        const results = new Map((this.executionState?.results || []).map(result => ['action:' + result.id, result]));
        const target = results.get(edge.targetId), source = results.get(edge.sourceId);
        const completed = result => ['completed', 'success'].includes(result?.status);
        if (edge.sourcePort === 'yes' || edge.sourcePort === 'no') {
            if (!completed(source) && source?.status !== 'skipped') return false;
            const passed = source.status === 'skipped' ? true : source.output?.passed ?? source.condition_result;
            if (passed === undefined || passed === null) return false;
            // The condition has already selected its route, even if the next node is still running.
            return edge.sourcePort === ([true, 1, '1', 'true'].includes(passed) ? 'yes' : 'no');
        }
        if (!completed(target)) return false;
        if (edge.sourceId.startsWith('trigger')) {
            const starts = this.nodeElements().filter(node => node.dataset.workflowNodeId.startsWith('trigger'));
            return edge.sourceId === (target.input?._workflow_start_node_id || this.executionState?.start_node_id || (starts.length === 1 ? starts[0].dataset.workflowNodeId : null));
        }
        if (!completed(source)) return false;
        return true;
    },

    applyExecutionState() {
        if (!this.executionState) return;
        const results = new Map((this.executionState.results || []).map((r) => [r.id.startsWith('trigger') ? r.id : 'action:' + r.id, r]));
        this.nodeElements().forEach((node) => {
            const card = node.querySelector('[data-workflow-node-card]');
            if (!card) return;
            const result = results.get(node.dataset.workflowNodeId);
            card.dataset.executionStatus = result?.status || (node.dataset.workflowNodeId === 'action:' + this.executionState.next_id ? 'next' : 'pending');
            const badge = card.querySelector('[data-workflow-node-result]');
            if (badge) {
                badge.hidden = !result;
                const count = String(result?.display_count ?? (['completed', 'success'].includes(result?.status) ? 1 : 0));
                if (badge.textContent !== count) badge.textContent = count;
                const title = result?.count_kind === 'iterations' ? 'Итераций цикла' : 'Сущностей';
                if (badge.title !== title) badge.title = title;
            }
        });
    },

    applyNodePosition(node, position) {
        this.applyNodeTranslation(node, position.x, position.y);

        // Branches share the tree markup, but each card moves independently.
        const branches = Array.from(node.children).find((child) => child.classList.contains('workflow-condition-split'));
        if (branches) {
            this.applyNodeTranslation(branches, -position.x, -position.y);
        }
    },

    applyNodeTranslation(node, x, y) {
        const current = (node.style.transform || '').match(/^translate3d\(([-\d.e+]+)px,\s*([-\d.e+]+)px,\s*0(?:px)?\)$/i);
        if (!current || Math.abs(Number(current[1]) - x) > 0.01 || Math.abs(Number(current[2]) - y) > 0.01) {
            node.style.transform = `translate3d(${x}px, ${y}px, 0px)`;
        }
    },

    observeCanvas() {
        const stage = this.$refs.stage;

        if (!stage) {
            return;
        }

        this.mutationObserver?.disconnect();
        this.mutationObserver = new MutationObserver((mutations) => {
            const edgeLayer = stage.querySelector('.workflow-node-edge-layer');
            const canvasChanged = mutations.some((mutation) => !edgeLayer?.contains(mutation.target));

            if (!canvasChanged) {
                return;
            }

            this.applyNodePositions();
            this.observeNodeGeometry();
            this.scheduleGraphRefreshAfterPaint();
        });
        // Livewire can remove client-owned inline positions without replacing a node.
        // Observe that reset too; position writes below are idempotent to avoid a loop.
        this.mutationObserver.observe(stage, {
            childList: true, subtree: true, attributes: true,
            attributeFilter: ['style', 'class', 'data-workflow-node-id', 'data-workflow-detached',
                'data-workflow-edge-source', 'data-workflow-edge-port', 'data-workflow-edge-target', 'data-workflow-loop-return'],
        });

        this.resizeObserver?.disconnect();
        this.resizeObserver = new ResizeObserver(() => this.scheduleGraphRefreshAfterPaint());
        this.observedGeometry = new Set();
        this.observedStage = stage;
        this.resizeObserver.observe(stage);

        if (this.$refs.viewport) {
            this.resizeObserver.observe(this.$refs.viewport);
        }
        this.observeNodeGeometry();
    },

    observeNodeGeometry() {
        if (!this.resizeObserver || !this.observedGeometry) return;
        const elements = new Set(this.$refs.stage?.querySelectorAll('[data-workflow-node-card], .workflow-node-port, .workflow-node-card__caption') || []);
        this.observedGeometry.forEach(element => {
            if (!elements.has(element)) {
                this.resizeObserver.unobserve(element);
                this.observedGeometry.delete(element);
            }
        });
        elements.forEach(element => {
            if (!this.observedGeometry.has(element)) {
                this.resizeObserver.observe(element);
                this.observedGeometry.add(element);
            }
        });
    },

    stageStyle() {
        return `transform: translate3d(${this.translateX}px, ${this.translateY}px, 0) scale(${this.scale});`;
    },

    saveViewport() {
        try {
            window.sessionStorage.setItem(this.layoutKey + ':viewport', JSON.stringify({
                x: this.translateX, y: this.translateY, scale: this.scale,
            }));
        } catch { /* Navigation still works when browser storage is unavailable. */ }
    },

    restoreViewport() {
        try {
            const view = JSON.parse(window.sessionStorage.getItem(this.layoutKey + ':viewport'));
            if (!view || ![view.x, view.y, view.scale].every(Number.isFinite) || view.scale < 0.01 || view.scale > 2) return false;
            this.translateX = view.x;
            this.translateY = view.y;
            this.scale = view.scale;
            return true;
        } catch { return false; }
    },

    onCanvasWheel(event) {
        if (event.target.closest('input, textarea, select, [contenteditable="true"], .workflow-canvas-note')) return;
        event.preventDefault();
        if (this.draggingNodeId !== null || this.selectionOrigin || this.pendingEdgeDrag || this.routeDrag) return;
        const viewport = this.$refs.viewport;
        const unit = event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? viewport.clientHeight : 1;
        const dx = event.deltaX * unit;
        const dy = event.deltaY * unit;
        if (event.ctrlKey || event.metaKey) {
            const rect = viewport.getBoundingClientRect();
            this.zoomCanvas(Math.exp(-dy * 0.01), {x: event.clientX - rect.left, y: event.clientY - rect.top});
            return;
        }
        this.translateX -= event.shiftKey && dx === 0 ? dy : dx;
        this.translateY -= event.shiftKey && dx === 0 ? 0 : dy;
        this.saveViewport();
        this.scheduleGraphRefresh();
    },

    fitView() {
        const viewport = this.$refs.viewport;
        const stage = this.$refs.stage;
        if (!viewport || !stage) return;
        const stageRect = stage.getBoundingClientRect();
        const currentScale = this.scale || 1;
        const boxes = this.nodeElements().flatMap((node) => [
            node.querySelector('[data-workflow-node-card]') || node,
            node.querySelector('.workflow-node-card__caption'),
        ].filter(Boolean).map((el) => el.getBoundingClientRect()));
        const edgeLayer = stage.querySelector?.('.workflow-node-edge-layer') || this.$refs.edgeLayer;
        boxes.push(...Array.from(edgeLayer?.querySelectorAll?.('[data-workflow-loop-return]') || []).map(el => el.getBoundingClientRect()));
        if (!boxes.length) return;
        const left = (Math.min(...boxes.map((r) => r.left)) - stageRect.left) / currentScale;
        const top = (Math.min(...boxes.map((r) => r.top)) - stageRect.top) / currentScale;
        const right = (Math.max(...boxes.map((r) => r.right)) - stageRect.left) / currentScale;
        const bottom = (Math.max(...boxes.map((r) => r.bottom)) - stageRect.top) / currentScale;
        const width = Math.max(1, right - left);
        const height = Math.max(1, bottom - top);
        this.scale = Math.max(0.01, Math.min(1, Math.max(1, viewport.clientWidth - 96) / width, Math.max(1, viewport.clientHeight - 96) / height));
        this.translateX = (viewport.clientWidth - width * this.scale) / 2 - left * this.scale;
        this.translateY = (viewport.clientHeight - height * this.scale) / 2 - top * this.scale;
        viewport.scrollTo?.({left: 0, top: 0});
        this.saveViewport();
        this.scheduleGraphRefreshAfterPaint();
    },

    zoomCanvas(factor, anchor = null) {
        const viewport = this.$refs.viewport;
        if (!viewport || !Number.isFinite(factor) || factor <= 0) return;
        const oldScale = this.scale || 1;
        const nextScale = Math.min(2, Math.max(0.01, oldScale * factor));
        const x = (anchor?.x ?? viewport.clientWidth / 2) + (viewport.scrollLeft || 0);
        const y = (anchor?.y ?? viewport.clientHeight / 2) + (viewport.scrollTop || 0);
        this.translateX = x - (x - this.translateX) * nextScale / oldScale;
        this.translateY = y - (y - this.translateY) * nextScale / oldScale;
        this.scale = nextScale;
        this.saveViewport();
        this.scheduleGraphRefreshAfterPaint();
    },

    resetView() {
        this.scale = 1;
        this.translateX = 0;
        this.translateY = 0;
        this.saveViewport();
        this.$refs.viewport?.scrollTo?.({left: 0, top: 0, behavior: 'smooth'});
        this.scheduleGraphRefresh();
    },

    centerView() {
        const viewport = this.$refs.viewport;
        const stage = this.$refs.stage;

        if (!viewport || !stage) {
            return;
        }

        const nodes = this.nodeElements();

        if (nodes.length === 0) {
            this.resetView();

            return;
        }

        const stageRect = stage.getBoundingClientRect();
        const currentScale = this.scale || 1;
        const bounds = nodes.reduce((result, node) => {
            const rect = (node.querySelector('[data-workflow-node-card]') || node).getBoundingClientRect();
            const left = (rect.left - stageRect.left) / currentScale;
            const top = (rect.top - stageRect.top) / currentScale;
            const right = left + rect.width / currentScale;
            const bottom = top + rect.height / currentScale;

            return {
                left: Math.min(result.left, left),
                top: Math.min(result.top, top),
                right: Math.max(result.right, right),
                bottom: Math.max(result.bottom, bottom),
            };
        }, {left: Infinity, top: Infinity, right: -Infinity, bottom: -Infinity});
        const contentHeight = bounds.bottom - bounds.top;

        this.scale = 1;
        this.translateX = 48 - bounds.left;
        this.translateY = Math.max(56, (viewport.clientHeight - contentHeight) / 2) - bounds.top;
        this.saveViewport();
        viewport.scrollTo?.({left: 0, top: 0, behavior: 'smooth'});
        this.scheduleGraphRefreshAfterPaint();
    },

    startCanvasInteraction(event) {
        if (this.connecting || this.routeDrag) return;
        const target = event.target instanceof Element ? event.target : null;
        const card = target?.closest('[data-workflow-node-card]');
        const node = card?.closest('[data-workflow-node-id]');

        if (node && this.$refs.stage?.contains(node)) {
            if ((event.shiftKey || event.ctrlKey || event.metaKey) && event.button === 0 && !target.closest('button, input, a')) {
                const id = node.dataset.workflowNodeId;
                this.selectedNodeIds = this.selectedNodeIds.includes(id) ? this.selectedNodeIds.filter(value=>value!==id) : [...this.selectedNodeIds,id];
                this.applyNodeSelection();
                this.suppressedNodeId = id;
                this.suppressClickUntil = Date.now()+350;
                event.preventDefault();
                return;
            }
            this.startNodeDrag(event, node);

            return;
        }

        this.startBoxSelection(event);
    },

    startNodeDrag(event, node = null) {
        if (event.button !== undefined && event.button !== 0) {
            return;
        }

        const target = event.target instanceof Element ? event.target : null;

        if (!target || !target.closest('[data-workflow-node-card]') || target.closest('button, a, input, textarea, select, [role="button"], .workflow-order-handle, .workflow-node-port')) {
            return;
        }

        node ??= target.closest('[data-workflow-node-id]');

        if (!node || target.closest('[data-workflow-node-id]') !== node) {
            return;
        }

        const nodeId = node.dataset.workflowNodeId;

        if (!nodeId) {
            return;
        }

        const position = this.positions[nodeId] || {x: 0, y: 0};
        if (!this.selectedNodeIds.includes(nodeId)) this.selectedNodeIds = [nodeId];
        this.applyNodeSelection();
        this.groupDragOrigins = Object.fromEntries(this.selectedNodeIds.map(id=>[id,{...(this.positions[id] || {x:0,y:0})}]));

        this.draggingNodeId = nodeId;
        this.draggingNodeElement = node;
        this.pointerId = event.pointerId;
        this.pointerStartX = event.clientX;
        this.pointerStartY = event.clientY;
        // Layout offsets are relative to the DOM, not to the visible grid.
        const anchor = this.cardPositions()[nodeId] || position;
        this.nodeStartX = Number(anchor.x) || 0;
        this.nodeStartY = Number(anchor.y) || 0;
        this.nodeDragMoved = false;
    },

    startBoxSelection(event) {
        if (event.button !== 0 || this.connecting || this.pendingEdgeDrag) {
            return;
        }

        if (event.target.closest('button, a, input, textarea, select, [role="button"], [draggable="true"], [data-workflow-node-card]')) {
            return;
        }

        const rect = this.$refs.viewport.getBoundingClientRect();
        this.selectionOrigin = {x:event.clientX,y:event.clientY,rect,previous:event.shiftKey ? [...this.selectedNodeIds] : []};
        if (!event.shiftKey) this.selectedNodeIds = [];
        this.applyNodeSelection();
        this.selectionBox = {x:event.clientX-rect.left,y:event.clientY-rect.top,width:0,height:0};
        this.pointerId = event.pointerId;
        this.$refs.viewport?.setPointerCapture?.(event.pointerId);
        event.preventDefault();
    },

    moveCanvas(event) {
        if (this.routeDrag && this.routeDrag.pointerId === event.pointerId) {
            const drag = this.routeDrag;
            const dx = (event.clientX-drag.x)/this.scale, dy = (event.clientY-drag.y)/this.scale;
            if (!drag.moved && Math.hypot(event.clientX-drag.x,event.clientY-drag.y) < 4) return;
            drag.moved = true;
            const route = {...drag.route};
            const bounded = value => Math.max(-100000, Math.min(100000, this.snapCoordinate(value)));
            if (route.kind === 'curve') { route.x = bounded(route.x+dx); route.y = bounded(route.y+dy); }
            else if (drag.part === 'exit') route.exitX = bounded(route.exitX+dx);
            else if (drag.part === 'entry') route.entryX = bounded(route.entryX+dx);
            else route.y = bounded(route.y+dy);
            this.edgeRoutes[drag.key] = route;
            event.preventDefault();
            this.scheduleGraphRefresh();
            return;
        }
        if (this.selectionOrigin && event.pointerId === this.pointerId) {
            const origin = this.selectionOrigin;
            const left = Math.min(origin.x,event.clientX), top = Math.min(origin.y,event.clientY);
            const right = Math.max(origin.x,event.clientX), bottom = Math.max(origin.y,event.clientY);
            this.selectionBox = {x:left-origin.rect.left,y:top-origin.rect.top,width:right-left,height:bottom-top};
            const ids = this.nodeElements().filter(node=>{
                const box = (node.querySelector('[data-workflow-node-card]') || node).getBoundingClientRect();
                return box.right>=left && box.left<=right && box.bottom>=top && box.top<=bottom;
            }).map(node=>node.dataset.workflowNodeId);
            this.selectedNodeIds = [...new Set([...origin.previous,...ids])];
            this.applyNodeSelection();
            event.preventDefault();
            return;
        }
        const pending = this.pendingEdgeDrag;
        if (pending && !pending.moved && pending.pointerId === event.pointerId && Math.hypot(event.clientX - pending.x, event.clientY - pending.y) >= 4) {
            pending.moved = true;
            if (pending.targetId) this.beginEdgeReconnect({sourceId: pending.sourceId, sourcePort: pending.sourcePort, targetId: pending.targetId}, event);
            else this.startConnection(pending.sourceId, pending.sourcePort, {
                button: 0, clientX: event.clientX, clientY: event.clientY,
                preventDefault: () => event.preventDefault(),
            });
            this.connectionDragOrigin = {x: pending.x, y: pending.y};
        }
        if (this.connecting) {
            this.connectionTargetId = this.connectionTargetAt(event);
            const box = this.connectionTargetId ? this.nodeElements().find(node => node.dataset.workflowNodeId === this.connectionTargetId)?.querySelector('.workflow-node-port--input')?.getBoundingClientRect?.() : null;
            this.connectionCursor = box ? {x:box.left+box.width/2, y:box.top+box.height/2} : {x:event.clientX, y:event.clientY};
            this.scheduleGraphRefresh();
            return;
        }
        if (this.draggingNodeId !== null && event.pointerId === this.pointerId) {
            const deltaX = this.snapCoordinate(this.nodeStartX + (event.clientX - this.pointerStartX) / this.scale) - this.nodeStartX;
            const deltaY = this.snapCoordinate(this.nodeStartY + (event.clientY - this.pointerStartY) / this.scale) - this.nodeStartY;

            if (!this.nodeDragMoved) {
                if (Math.hypot(event.clientX - this.pointerStartX, event.clientY - this.pointerStartY) < 4) {
                    return;
                }

                this.nodeDragMoved = true;
                // Capturing on pointerdown retargets even a click to the viewport.
                this.$refs.viewport?.setPointerCapture?.(event.pointerId);
                this.$refs.viewport?.classList.add('is-node-dragging');
                this.draggingNodeElement?.classList.add('workflow-node--dragging');
            }

            event.preventDefault();

            Object.entries(this.groupDragOrigins).forEach(([id, origin]) => {
                this.positions[id] = {x:Math.round((origin.x+deltaX)*10)/10,y:Math.round((origin.y+deltaY)*10)/10};
            });
            this.applyNodePositions();

            this.scheduleGraphRefresh();

            return;
        }

    },

    stopCanvasInteraction(event) {
        if (this.routeDrag?.pointerId === event.pointerId) {
            if (event.type === 'pointercancel') this.cancelEdgeRouteDrag();
            else {
                const moved = this.routeDrag.moved;
                this.routeDrag = null;
                this.$refs.viewport?.releasePointerCapture?.(event.pointerId);
                if (moved) this.saveNodeLayout();
            }
            this.scheduleGraphRefresh();
            return;
        }
        if (this.selectionOrigin && event.pointerId === this.pointerId) {
            this.selectionOrigin = null;
            this.selectionBox = null;
            this.pointerId = null;
            this.$refs.viewport?.releasePointerCapture?.(event.pointerId);
            return;
        }
        if (this.pendingEdgeDrag?.pointerId === event.pointerId) {
            if (this.pendingEdgeDrag.moved) this.suppressEdgeClickUntil = Date.now() + 350;
            else if (event.type !== 'pointercancel') {
                // Capturing the pointer retargets the click; explicitly open on a tap.
                const edge = this.pendingEdgeDrag;
                this.openEdgePalette(edge.sourceId, edge.sourcePort, edge.targetId);
                this.suppressEdgeClickUntil = Date.now() + 350;
            }
            this.pendingEdgeDrag = null;
        }
        if (this.$refs.viewport?.hasPointerCapture?.(event.pointerId) && this.draggingNodeId === null) {
            this.$refs.viewport.releasePointerCapture(event.pointerId);
        }
        if (this.connecting) {
            if (event.type === 'pointercancel') this.cancelConnection();
            else {
                // Pointer capture can retarget release to the source control.
                // Resolve the actual drop position, including a detached card.
                const targetId = this.connectionTargetAt(event);
                if (targetId) this.finishConnection(targetId, event);
                else if (this.connectionDragOrigin && Math.hypot(event.clientX - this.connectionDragOrigin.x, event.clientY - this.connectionDragOrigin.y) >= 4) {
                    if (!this.openPaletteAtDrop(event)) this.cancelConnection();
                }
            }
        }
        if (this.draggingNodeId !== null && event.pointerId === this.pointerId) {
            const draggedNodeId = this.draggingNodeId;
            const draggedNodeElement = this.draggingNodeElement;
            const moved = this.nodeDragMoved;

            this.draggingNodeId = null;
            this.draggingNodeElement = null;
            this.nodeDragMoved = false;
            this.pointerId = null;
            this.$refs.viewport?.releasePointerCapture?.(event.pointerId);
            this.$refs.viewport?.classList.remove('is-node-dragging');
            draggedNodeElement?.classList.remove('workflow-node--dragging');

            if (moved) {
                this.suppressedNodeId = draggedNodeId;
                this.suppressClickUntil = Date.now() + 350;
                this.saveNodeLayout();
            }

            this.scheduleGraphRefresh();
            this.scheduleGraphRefreshAfterPaint();

            return;
        }

    },

    suppressNodeClick(event) {
        const target = event.target instanceof Element ? event.target : null;
        const nodeId = target?.closest('[data-workflow-node-id]')?.dataset.workflowNodeId;
        if (nodeId && (event.shiftKey || event.ctrlKey || event.metaKey)
            && target.closest('[data-workflow-node-card]') && !target.closest('button, a, input, textarea, select')) {
            event.preventDefault();
            event.stopImmediatePropagation();
            event.stopPropagation();
            return;
        }
        if (Date.now() > this.suppressClickUntil) {
            return;
        }

        if (!nodeId || nodeId !== this.suppressedNodeId) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        event.stopPropagation();
        this.suppressClickUntil = 0;
        this.suppressedNodeId = null;
    },

    scheduleGraphRefresh() {
        if (this.refreshFrame !== null) {
            return;
        }

        this.refreshFrame = window.requestAnimationFrame(() => {
            this.refreshFrame = null;
            this.drawConnections();
        });
    },

    scheduleGraphRefreshAfterPaint() {
        if (this.settleFrame !== null) {
            window.cancelAnimationFrame(this.settleFrame);
        }

        this.settleFrame = window.requestAnimationFrame(() => {
            this.settleFrame = window.requestAnimationFrame(() => {
                this.settleFrame = null;
                this.drawConnections();
            });
        });
    },

    roundedEdgeRoute(raw) {
        const points = [];
        for (const p of raw) {
            const last = points.at(-1), before = points.at(-2);
            if (last && p.x === last.x && p.y === last.y) continue;
            if (before && ((before.x === last.x && last.x === p.x) || (before.y === last.y && last.y === p.y))
                && (last.x-before.x)*(p.x-last.x)+(last.y-before.y)*(p.y-last.y) >= 0) points.pop();
            points.push(p);
        }
        let d = `M ${points[0].x} ${points[0].y}`;
        for (let i = 1; i < points.length-1; i++) {
            const a = points[i-1], b = points[i], c = points[i+1];
            const before = Math.hypot(b.x-a.x,b.y-a.y), after = Math.hypot(c.x-b.x,c.y-b.y);
            const r = Math.min(10,before/2,after/2);
            d += ` L ${b.x+(a.x-b.x)*r/before} ${b.y+(a.y-b.y)*r/before} Q ${b.x} ${b.y} ${b.x+(c.x-b.x)*r/after} ${b.y+(c.y-b.y)*r/after}`;
        }
        const end = points.at(-1);
        return {d:d+` L ${end.x} ${end.y}`,right:Math.max(...points.map(p=>p.x)),bottom:Math.max(...points.map(p=>p.y))};
    },

    connectionRoute(start, end, loopReturn, saved = null, sourceVector = {x:1,y:0}, targetVector = {x:-1,y:0}, lane = 0) {
        // Only endpoints and a user-owned route affect geometry. Other cards are
        // deliberately not obstacles: their opaque layer covers passing edges.
        if (loopReturn) {
            const route = saved?.kind === 'loop' ? {...saved}
                : {kind:'loop',exitX:start.x+24,entryX:end.x-24,y:Math.max(start.y,end.y)+144+lane*24};
            const x = (route.exitX+route.entryX)/2;
            return {...this.roundedEdgeRoute([start,{x:route.exitX,y:start.y},{x:route.exitX,y:route.y},
                {x:route.entryX,y:route.y},{x:route.entryX,y:end.y},end]),x,y:route.y,route,
                handles:[{part:'exit',x:route.exitX,y:(start.y+route.y)/2},
                    {part:'lane',x,y:route.y},{part:'entry',x:route.entryX,y:(end.y+route.y)/2}]};
        }
        const length = (a,b) => Math.min(240,Math.max(48,Math.hypot(b.x-a.x,b.y-a.y)*.38));
        if (saved?.kind === 'curve' || end.x <= start.x) {
            const route = saved?.kind === 'curve' ? {...saved}
                : {kind:'curve',x:(start.x+end.x)/2,y:Math.max(start.y,end.y)+144};
            const a = length(start,route), b = length(route,end), direction = end.x > start.x ? 1 : -1;
            return {d:`M ${start.x} ${start.y} C ${start.x+sourceVector.x*a} ${start.y+sourceVector.y*a}, ${route.x-direction*a} ${route.y}, ${route.x} ${route.y} C ${route.x+direction*b} ${route.y}, ${end.x+targetVector.x*b} ${end.y+targetVector.y*b}, ${end.x} ${end.y}`,
                x:route.x,y:route.y,right:Math.max(start.x+a,end.x+b,route.x+a,route.x+b),bottom:Math.max(start.y,end.y,route.y),
                route,handles:[{part:'point',x:route.x,y:route.y}]};
        }
        const curve = length(start,end);
        const a = {x:start.x+sourceVector.x*curve,y:start.y+sourceVector.y*curve};
        const b = {x:end.x+targetVector.x*curve,y:end.y+targetVector.y*curve};
        const x = (start.x+3*a.x+3*b.x+end.x)/8, y = (start.y+3*a.y+3*b.y+end.y)/8;
        return {d:`M ${start.x} ${start.y} C ${a.x} ${a.y}, ${b.x} ${b.y}, ${end.x} ${end.y}`,
            x,y,right:Math.max(start.x,a.x,b.x,end.x),bottom:Math.max(start.y,a.y,b.y,end.y),
            route:{kind:'curve',x,y},handles:[{part:'point',x,y}]};
    },

    drawConnections() {
        const stage = this.$refs.stage;
        const edgeLayer = stage?.querySelector('.workflow-node-edge-layer') || this.$refs.edgeLayer;

        if (!stage || !edgeLayer) {
            return;
        }

        const nodes = this.nodeElements();
        const nodeById = new Map(nodes.map((node) => [node.dataset.workflowNodeId, node]));
        const stageRect = stage.getBoundingClientRect();
        const scale = this.scale || 1;
        const paths = [];
        const controlLayer = stage.querySelector('.workflow-node-edge-controls') || this.$refs.edgeControls;
        const controls = Array.from(controlLayer?.querySelectorAll('[data-workflow-edge-source]') || []);
        const positionedControls = new Set();

        const positionControl = (button, x, y) => {
            if (!button) return;

            const remove = button.nextElementSibling;
            const paired = remove?.hasAttribute('data-workflow-edge-delete');
            // Centre the whole pair on the actual path, in canvas coordinates.
            const halfGap = paired ? ((button.getBoundingClientRect?.().width / scale || 16) + 4) / 2 : 0;
            const addX = x - halfGap;
            // Compare numeric coordinates: browsers normalize CSS decimal precision.
            if (Math.abs(parseFloat(button.style.left) - addX) > 0.01 || !button.style.left) button.style.left = `${addX}px`;
            if (Math.abs(parseFloat(button.style.top) - y) > 0.01 || !button.style.top) button.style.top = `${y}px`;
            if (button.style.visibility !== 'visible') button.style.visibility = 'visible';
            positionedControls.add(button);
            if (paired) {
                if (Math.abs(parseFloat(remove.style.left) - (x + halfGap)) > 0.01 || !remove.style.left) remove.style.left = `${x + halfGap}px`;
                if (Math.abs(parseFloat(remove.style.top) - y) > 0.01 || !remove.style.top) remove.style.top = `${y}px`;
                if (remove.style.visibility !== 'visible') remove.style.visibility = 'visible';
            }
        };
        const sourceSelector = (port) => ['yes', 'no', 'each', 'done'].includes(port)
            ? `.workflow-node-port--output-${port}`
            : '.workflow-node-port--output:not(.workflow-node-port--output-yes):not(.workflow-node-port--output-no):not(.workflow-node-port--output-each):not(.workflow-node-port--output-done)';

        const portVector = (portRect, cardRect, fallback) => {
            if (!portRect || !cardRect) {
                return fallback;
            }

            const x = portRect.left + portRect.width / 2;
            const y = portRect.top + portRect.height / 2;
            const sides = [
                {distance: Math.abs(x - cardRect.left), x: -1, y: 0},
                {distance: Math.abs(x - cardRect.right), x: 1, y: 0},
                {distance: Math.abs(y - cardRect.top), x: 0, y: -1},
                {distance: Math.abs(y - cardRect.bottom), x: 0, y: 1},
            ];

            return sides.sort((a, b) => a.distance - b.distance)[0];
        };
        let maxX = stage.clientWidth;
        let maxY = stage.clientHeight;
        let returnLane = 0;

        controls.filter((button) => button.dataset.workflowEdgeTarget).forEach((button) => {
            const sourceId = button.dataset.workflowEdgeSource;
            const sourcePort = button.dataset.workflowEdgePort || 'output';
            const sourceNode = nodeById.get(sourceId);
            const targetNode = nodeById.get(button.dataset.workflowEdgeTarget);

            if (!sourceNode || !targetNode) {
                return;
            }

            const sourcePortSelector = sourceSelector(sourcePort);
            const sourceCard = sourceNode.querySelector('[data-workflow-node-card]');
            const targetCard = targetNode.querySelector('[data-workflow-node-card]');
            const sourcePortElement = sourceCard?.querySelector(sourcePortSelector);
            const targetPortElement = targetCard?.querySelector('.workflow-node-port--input');
            const sourceRect = (sourcePortElement || sourceCard || sourceNode).getBoundingClientRect();
            const targetRect = (targetPortElement || targetCard || targetNode).getBoundingClientRect();
            const sourceCardRect = sourceCard?.getBoundingClientRect();
            const targetCardRect = targetCard?.getBoundingClientRect();
            const startX = ((sourcePortElement ? sourceRect.left + sourceRect.width / 2 : sourceRect.right) - stageRect.left) / scale;
            const startY = (sourceRect.top + sourceRect.height / 2 - stageRect.top) / scale;
            const endX = ((targetPortElement ? targetRect.left + targetRect.width / 2 : targetRect.left) - stageRect.left) / scale;
            const endY = (targetRect.top + targetRect.height / 2 - stageRect.top) / scale;
            // Angular routing belongs to semantic loop returns, not the position
            // of a node or an ordinary connection passing near a caption.
            const loopReturn = button.dataset.workflowLoopReturn === 'true';
            const edge = {sourceId,sourcePort,targetId:button.dataset.workflowEdgeTarget};
            const sourceVector = portVector(sourcePortElement ? sourceRect : null, sourceCardRect, {x:1,y:0});
            const targetVector = portVector(targetPortElement ? targetRect : null, targetCardRect, {x:-1,y:0});
            const route = this.connectionRoute({x:startX,y:startY},{x:endX,y:endY},loopReturn,
                this.edgeRoutes[this.edgeRouteKey(edge)],sourceVector,targetVector,loopReturn ? returnLane++ : 0);
            paths.push({d:route.d,port:sourcePort,active:button.dataset.workflowEdgeActive,loopReturn,
                edge,control:button,route:route.route,handles:route.handles});
            positionControl(button,route.x,route.y);
            maxX = Math.max(maxX,route.right);
            maxY = Math.max(maxY,route.bottom+24);
        });

        controls.filter((button) => !button.dataset.workflowEdgeTarget).forEach((button) => {
            const sourceNode = nodeById.get(button.dataset.workflowEdgeSource);

            if (!sourceNode) return;

            const port = button.dataset.workflowEdgePort || 'output';
            const card = sourceNode.querySelector('[data-workflow-node-card]') || sourceNode;
            const portElement = card.querySelector(sourceSelector(port));
            const rect = (portElement || card).getBoundingClientRect();
            const startX = ((portElement ? rect.left + rect.width / 2 : rect.right) - stageRect.left) / scale;
            const startY = (rect.top + rect.height / 2 - stageRect.top) / scale;
            // This is an affordance for another connection, not an existing edge.
            // Keep it near the card without drawing a misleading, undeletable tail.
            if (button.dataset.workflowEdgeBranch) {
                positionControl(button, startX + 12, startY + 28);
                maxX = Math.max(maxX, startX + 24);
                maxY = Math.max(maxY, startY + 40);
                return;
            }
            const buttonX = startX + 100;
            const buttonY = startY + (['yes', 'done'].includes(port) ? -44 : ['no', 'each'].includes(port) ? 44 : 0);
            const buttonRadius = (button.getBoundingClientRect?.().width || 22 * scale) / scale / 2;
            const endX = buttonX - buttonRadius;

            paths.push({
                d: `M ${startX} ${startY} C ${startX + 44} ${startY}, ${endX - 32} ${buttonY}, ${endX} ${buttonY}`,
                port,
                active: button.dataset.workflowEdgeActive,
                edge: {sourceId: button.dataset.workflowEdgeSource, sourcePort: port, targetId: null},
            });
            positionControl(button, buttonX, buttonY);
            maxX = Math.max(maxX, buttonX + buttonRadius);
            maxY = Math.max(maxY, buttonY + buttonRadius);
        });

        controls.forEach((button) => {
            if (!positionedControls.has(button)) {
                if (button.style.visibility !== 'hidden') button.style.visibility = 'hidden';
                const remove = button.nextElementSibling;
                if (remove?.hasAttribute('data-workflow-edge-delete') && remove.style.visibility !== 'hidden') remove.style.visibility = 'hidden';
            }
        });

        if (this.connecting && this.connectionCursor) {
            const source = nodeById.get(this.connecting.sourceId);
            const port = source?.querySelector(sourceSelector(this.connecting.sourcePort));
            if (port) {
                const rect = port.getBoundingClientRect();
                const x = (rect.left + rect.width / 2 - stageRect.left) / scale;
                const y = (rect.top + rect.height / 2 - stageRect.top) / scale;
                const endX = (this.connectionCursor.x - stageRect.left) / scale;
                const endY = (this.connectionCursor.y - stageRect.top) / scale;
                paths.push({d: `M ${x} ${y} C ${x + 80} ${y}, ${endX - 80} ${endY}, ${endX} ${endY}`, port: this.connecting.sourcePort, preview: true});
            }
        }
        const namespace = 'http://www.w3.org/2000/svg';
        const definitions = document.createElementNS(namespace, 'defs');
        const markerColors = {
            default: '#78716c',
            completed: '#4d9c70',
        };

        Object.entries(markerColors).forEach(([name, color]) => {
            const marker = document.createElementNS(namespace, 'marker');
            const arrow = document.createElementNS(namespace, 'path');

            marker.setAttribute('id', `workflow-edge-arrow-${name}`);
            marker.setAttribute('viewBox', '0 0 10 10');
            marker.setAttribute('refX', '8');
            marker.setAttribute('refY', '5');
            marker.setAttribute('markerWidth', '5');
            marker.setAttribute('markerHeight', '5');
            marker.setAttribute('orient', 'auto-start-reverse');
            arrow.setAttribute('d', 'M 0 0 L 10 5 L 0 10 z');
            arrow.setAttribute('fill', color);
            marker.appendChild(arrow);
            definitions.appendChild(marker);
        });

        const edgePaths = paths.flatMap(({d, port, active, edge, preview, control, loopReturn, route, handles}) => {
            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            const completed = !preview && this.edgeCompleted(edge, active);
            const marker = completed ? 'completed' : 'default';

            path.setAttribute('d', d);
            path.setAttribute('class', `workflow-node-edge${completed ? ' workflow-node-edge--completed' : ''}`);
            path.setAttribute('marker-end', `url(#workflow-edge-arrow-${marker})`);
            if (loopReturn) path.setAttribute('data-workflow-loop-return', 'true');
            path.dataset.workflowEdgePort = port;
            if (active !== undefined) path.dataset.workflowEdgeActive = active;
            if (preview) path.classList.add('workflow-node-edge--preview');
            if (edge && active === undefined) {
                const hit = document.createElementNS(namespace, 'path');
                hit.setAttribute('d', d);
                hit.setAttribute('class', 'workflow-node-edge-hit');
                hit.dataset.source = edge.sourceId;
                hit.dataset.target = edge.targetId || '';
                hit.addEventListener('pointerdown', (event) => {
                    event.stopPropagation();
                    if (event.button !== 0) return;
                    if (edge.targetId) this.startEdgeRouteDrag(edge, route, event);
                    else this.startConnection(edge.sourceId, edge.sourcePort, event);
                });
                hit.addEventListener('click', (event) => event.stopPropagation());
                if (edge.targetId && control) {
                    hit.addEventListener('pointerenter', () => this.showEdgeControls(control));
                    hit.addEventListener('pointerleave', () => this.hideEdgeControls(control));
                }
                const selected = this.selectedEdge && this.edgeRouteKey(edge) === this.edgeRouteKey(this.selectedEdge);
                if (selected) path.classList.add('is-selected');
                const grips = selected && route ? (handles || []).map(handle => {
                    const grip = document.createElementNS(namespace, 'circle');
                    grip.setAttribute('cx',handle.x);
                    grip.setAttribute('cy',handle.y);
                    grip.setAttribute('r',Math.min(20,5/scale));
                    grip.setAttribute('class','workflow-edge-route-handle');
                    grip.dataset.routePart = handle.part;
                    grip.addEventListener('pointerdown',event => this.startEdgeRouteDrag(edge,route,event,handle.part));
                    grip.addEventListener('click',event => event.stopPropagation());
                    return grip;
                }) : [];
                return [hit, path, ...grips];
            }
            return [path];
        });

        edgeLayer.replaceChildren(definitions, ...edgePaths);
        edgeLayer.setAttribute('width', String(Math.ceil(maxX + 96)));
        edgeLayer.setAttribute('height', String(Math.ceil(maxY + 96)));
        edgeLayer.style.overflow = 'visible';
        edgeLayer.style.pointerEvents = 'none';
    },
});

window.workflowDebugPanel = () => ({
    selectedIndex: -1, playing: false, busy: false,
    async startAndRun() {
        if (this.busy || this.playing) return;
        this.busy = true;
        let started = false;
        try { started = await this.$wire.startWorkflowDebug(); } finally { this.busy = false; }
        if (started) await this.run();
    },
    current() { return (this.$wire.debugState.results || [])[this.selectedIndex] || {}; },
    pretty(value) { return JSON.stringify(value, null, 2); },
    statusLabel(value) { return {completed:'Выполнено', simulated:'Без изменений', skipped:'Пропущено', error:'Ошибка', validation_error:'Ошибка настроек'}[value] || value; },
    selectNode(id) { this.selectedIndex = (this.$wire.debugState.results || []).findIndex((r) => r.id === id); },
    async next() { if (this.busy) return false; this.busy = true; try { return await this.$wire.nextWorkflowDebugStep(); } finally { this.busy = false; } },
    async run() { this.playing = true; try { while(this.playing && await this.next()) {} } finally { this.playing = false; } },
});

window.workflowExecutionViewer = (graph) => ({
    graph, selectedIndex: graph.results.length ? 0 : -1, pendingNode: null, exchangeIndex: 0,
    updateGraph(next) {
        const selected = this.selectedNodeId();
        const record = this.current().execution_id;
        const index = this.selectedIndex;
        this.graph = next;
        if (selected === 'trigger') { this.selectedIndex = -1; this.pendingNode = null; return; }
        const match = record != null ? next.results.findIndex(r => r.execution_id === record) : index;
        if (match >= 0 && next.results[match] && 'action:' + next.results[match].id === selected) {
            this.selectedIndex = match;
            return;
        }
        this.selectNode(selected);
    },
    isAmoStep() { return (this.current().type || '').startsWith('amocrm_'); },
    exchanges() { return this.current().output?.amo_exchange || []; },
    exchange() { return this.exchanges()[this.exchangeIndex] || this.exchanges()[0]; },
    requestData() {
        if (!this.isAmoStep()) return this.current().resolved_input ?? this.current().input ?? this.graph.trigger_data ?? {};
        if (this.exchange()) return this.exchange().request?.body ?? this.exchange().request?.query ?? null;
        return {message: this.current().output?.deduplicated
            ? 'Найден существующий контакт: POST создания не отправлялся. HTTP-журнал этого старого шага не сохранён.'
            : 'HTTP-запрос не сохранён. Ниже настройки шага, а не отправленное тело запроса.',
            configuration: this.current().resolved_input ?? this.current().input ?? {}};
    },
    responseData() {
        if (this.isAmoStep() && this.exchange() && this.current().display_responses) return this.current().display_responses[this.exchangeIndex] ?? this.current().display_responses[0] ?? null;
        if (Object.prototype.hasOwnProperty.call(this.current(), 'display_output')) return this.current().display_output;
        if (this.isAmoStep() && this.exchange()) return this.exchange().response?.body ?? null;
        const output = this.current().output ?? {};
        return this.current().error ? {...output, error:this.current().error} : output;
    },
    executions() { return this.graph.results.map((result,index) => ({...result,index})).filter(result => result.id === this.current().id); },
    selectExecution(index) { this.pendingNode = null; this.exchangeIndex = 0; this.selectedIndex = Number(index); },
    executionLabel(result) {
        const time = result.started_at ? new Date(result.started_at).toLocaleTimeString('ru-RU') : '';
        return 'Выполнение ' + result.occurrence + ' из ' + result.occurrence_count + (time ? ' · ' + time : '') + ' · ' + this.statusLabel(result.status);
    },
    current() { return this.pendingNode ? {id:this.pendingNode.id.slice(7),name:this.pendingNode.name,status:'not_executed',input:this.pendingNode.action.config,output:{message:'Эта нода не выполнялась в выбранном запуске.'}} : this.graph.results[this.selectedIndex] || {}; },
    selectedNodeId() { return this.current().id ? 'action:' + this.current().id : 'trigger'; },
    selectNode(id) { this.exchangeIndex = 0; this.selectedIndex = id === 'trigger' ? -1 : this.graph.results.findLastIndex((r) => 'action:' + r.id === id); this.pendingNode = this.selectedIndex === -1 && id !== 'trigger' ? this.graph.nodes.find((node) => node.id === id) : null; },
    previous() { this.exchangeIndex = 0; this.pendingNode = null; this.selectedIndex = Math.max(-1, this.selectedIndex - 1); },
    next() { this.exchangeIndex = 0; this.pendingNode = null; this.selectedIndex = Math.min(this.graph.results.length - 1, this.selectedIndex + 1); },
    pretty(value) { return JSON.stringify(value, null, 2); },
    statusLabel(value) { return {completed:'Выполнено', skipped:'Пропущено', failed:'Ошибка', error:'Ошибка', running:'Выполняется'}[value] || value; },
});

window.workflowJsonViewer = (initialValue) => ({
    value: initialValue,
    signature: null,
    collapsed: [],
    initialized: false,
    init() {
        this.signature = JSON.stringify(this.value);
        this.collapseNested();
        this.initialized = true;
    },
    setValue(value) {
        const signature = JSON.stringify(value);
        if (this.signature === signature && this.initialized) return;
        this.value = value;
        this.signature = signature;
        this.collapseNested();
        this.initialized = true;
    },
    type(value) {
        if (value === null) return 'null';
        if (typeof value === 'boolean') return 'bool';
        if (typeof value === 'number') return Number.isInteger(value) ? 'int' : 'float';
        if (typeof value === 'string') return 'string';
        return 'punctuation';
    },
    entries(value) {
        if (Array.isArray(value)) return value.map((child, index) => [String(index), child]);
        if (value && typeof value === 'object') return Object.entries(value);
        return [];
    },
    branchPaths(value = this.value, path = '$', depth = 0, result = []) {
        const entries = this.entries(value);
        if (entries.length) result.push({path, depth});
        entries.forEach(([key, child]) => {
            if (child && typeof child === 'object') this.branchPaths(child, path + '.' + key, depth + 1, result);
        });
        return result;
    },
    collapseNested() {
        this.collapsed = this.branchPaths().filter((branch) => branch.depth >= 1).map((branch) => branch.path);
    },
    collapseAll() {
        this.collapsed = this.branchPaths().map((branch) => branch.path);
    },
    expandAll() {
        this.collapsed = [];
    },
    toggle(path) {
        this.collapsed = this.collapsed.includes(path)
            ? this.collapsed.filter((item) => item !== path)
            : [...this.collapsed, path];
    },
    get rows() {
        const rows = [];
        const visit = (value, path = '$', depth = 0, label = '', comma = '', parentArray = false) => {
            const isArray = Array.isArray(value);
            const isObject = value !== null && typeof value === 'object' && !isArray;
            const entries = this.entries(value);
            const container = isArray || isObject;
            const open = isArray ? '[' : '{';
            const close = isArray ? ']' : '}';
            const collapsed = container && this.collapsed.includes(path);
            const key = depth === 0 || parentArray ? '' : JSON.stringify(label) + ': ';

            if (!container) {
                rows.push({id: path, path, depth, label: key, type: this.type(value), branch: false, collapsed: false, text: (JSON.stringify(value) ?? 'null') + comma});
                return;
            }

            rows.push({
                id: path + ':open', path, depth, label: key, type: 'punctuation',
                branch: entries.length > 0, collapsed,
                text: open + (collapsed ? ' … ' + close + comma : entries.length ? '' : close + comma),
            });

            if (!collapsed && entries.length) {
                entries.forEach(([childKey, child], index) => visit(child, path + '.' + childKey, depth + 1, childKey, index < entries.length - 1 ? ',' : '', isArray));
                rows.push({id: path + ':close', path, depth, label: '', type: 'punctuation', branch: false, collapsed: false, text: close + comma});
            }
        };
        visit(this.value);
        return rows;
    },
});

window.workflowExpressionPicker = (sources, customFields = []) => ({
    // Source data is immutable for this keyed panel; only selection/folding needs reactivity.
    sources: Object.freeze(sources), customFields: Object.freeze(customFields), selectedId: sources.findLast(source => source.available)?.id ?? sources.at(-1)?.id ?? '', limit: 30,
    field: null, fieldLabel: '', preview: null, expanded: [], collapsedJson: [],
    get selectedSource() { return this.sources.find(source => source.id === this.selectedId); },
    get jsonRows() {
        const fields = this.selectedSource?.fields ?? [];
        const children = new Map();
        for (const field of fields) {
            const siblings = children.get(field.parent) ?? [];
            siblings.push(field); children.set(field.parent, siblings);
        }
        const rows = [];
        const visit = (item, depth, comma = '', parentType = null) => {
            const container = ['array', 'object'].includes(item.type);
            const nested = children.get(item.key) ?? [];
            const open = item.type === 'array' ? '[' : '{';
            const close = item.type === 'array' ? ']' : '}';
            const collapsed = this.collapsedJson.includes(item.key);
            const label = depth && parentType !== 'array' ? JSON.stringify(item.label) + ': ' : '';
            const value = container || !item.available ? 'null' : JSON.stringify(item.value) ?? 'null';
            rows.push({id: item.key + ':value', item, depth, label, type: container ? 'punctuation' : item.type,
                branch: container && nested.length > 0, collapsed,
                text: container ? open + (collapsed ? ' … ' + close + comma : nested.length ? '' : close + comma) : value + comma});
            if (container && nested.length && !collapsed) {
                nested.forEach((child, index) => visit(child, depth + 1, index < nested.length - 1 ? ',' : '', item.type));
                rows.push({id: item.key + ':close', depth, label: '', type: 'punctuation', text: close + comma});
            }
        };
        if (fields[0]) visit(fields[0], 0);
        return rows;
    },
    toggleJson(item) { this.collapsedJson = this.collapsedJson.includes(item.key) ? this.collapsedJson.filter(key => key !== item.key) : [...this.collapsedJson, item.key]; },
    get treeFields() {
        const fields = this.selectedSource?.fields ?? [];
        const parents = new Map(fields.map(item => [item.key, item.parent]));
        const branches = new Set(fields.map(item => item.parent).filter(key => key != null));
        return fields.filter(item => {
            if (item.key === '' && branches.has('')) return false;
            let parent = item.parent;
            while (parent != null && parent !== '') {
                if (!this.expanded.includes(parent)) return false;
                parent = parents.get(parent);
            }
            return true;
        }).map(item => ({...item, branch: branches.has(item.key)}));
    },
    get visibleFields() { return this.treeFields.slice(0, this.limit); },
    toggleField(item) { this.expanded = this.expanded.includes(item.key) ? this.expanded.filter(key => key !== item.key) : [...this.expanded, item.key]; },
    fieldSummary(item) {
        if (item.type === 'array') return item.count == null ? '[]' : '[' + item.count + ']';
        if (item.type === 'object') return '{}';
        return item.available ? this.valueText(item.value) : '—';
    },
    rememberField(event) {
        const field = event.target;
        const scope = this.$el.closest('.workflow-inspector, .fi-modal-window');
        if (!field?.matches?.('input:not([type=hidden]):not([type=search]):not([type=checkbox]):not([type=radio]):not([type=number]), textarea') || field.closest('.workflow-expression-picker') || field.readOnly || field.disabled || !scope?.contains(field)) return;
        // Select dropdowns contain text search inputs, but those are not configuration values.
        if (!field.hasAttribute('data-workflow-value-input') && !Array.from(field.attributes).some((attribute) => attribute.name.startsWith('wire:model'))) return;
        this.field = field;
        field.closest('.workflow-value-field')?.dispatchEvent(new CustomEvent('workflow-expression-suggestions', {detail: {sources: this.sources, customFields: this.customFields}}));
        this.fieldLabel = field.closest('.fi-fo-field')?.querySelector('label')?.textContent?.trim() || field.getAttribute('aria-label') || 'выбранное поле';
        this.refreshPreview();
    },
    valueText(value) { const text = typeof value === 'string' ? value : JSON.stringify(value); return (text ?? 'null').slice(0, 180); },
    async insert(item) {
        if (!this.field?.isConnected) {
            try { await navigator.clipboard.writeText(item.expression); this.preview = 'Скопировано'; } catch { this.preview = item.expression; }
            return;
        }
        const field = this.field;
        field.closest('.workflow-value-field')?.dispatchEvent(new CustomEvent('workflow-expression-field'));
        const isCode = Array.from(field.attributes).some(attribute => attribute.name.startsWith('wire:model') && attribute.value.endsWith('.javascript_code'));
        const expression = isCode ? item.expression.replace(/^\{\{\s*|\s*\}\}$/g, '') : item.expression;
        const start = field.selectionStart ?? field.value.length;
        const end = field.selectionEnd ?? start;
        const value = field.value.slice(0, start) + expression + field.value.slice(end);
        const prototype = field.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
        Object.getOwnPropertyDescriptor(prototype, 'value').set.call(field, value);
        field.dispatchEvent(new Event('input', {bubbles:true}));
        field.dispatchEvent(new Event('change', {bubbles:true}));
        field.focus();
        try { field.setSelectionRange?.(start + expression.length, start + expression.length); } catch { /* Some input types do not expose a text selection. */ }
        this.refreshPreview();
    },
    updatePreview(event) { if (event.target === this.field) this.refreshPreview(); },
    refreshPreview() {
        if (!this.field?.value.includes('{{')) { this.preview = null; return; }
        const fields = this.sources.flatMap((source) => source.fields);
        this.preview = this.field.value.replace(/\{\{\s*([^{}]+?)\s*\}\}/g, (full, expression) => {
            const item = fields.find((item) => item.expression.replace(/^\{\{\s*|\s*\}\}$/g, '') === expression.trim());
            return item?.available ? this.fieldSummary(item) : '⟨нет данных этого шага⟩';
        });
    },
});

window.workflowValueField = (state) => ({
    state, mode: 'value', sources: [], customFields: [], suggestions: [], suggestionIndex: 0, tokenStart: 0, tokenEnd: 0,
    init() { if (typeof this.state === 'boolean') this.state = this.state ? '1' : '0'; this.mode = typeof this.state === 'string' && this.state.includes('{{') ? 'expression' : 'value'; },
    suggest(event) {
        const field = event.target;
        if (!field?.hasAttribute?.('data-workflow-value-input')) return;
        const text = field.value;
        const cursor = field.selectionStart ?? text.length;
        const before = text.slice(0, cursor);
        const start = before.lastIndexOf('{{');
        if (start < 0 || before.slice(start).includes('}}')) { this.suggestions = []; return; }
        this.mode = 'expression';
        this.tokenStart = start;
        const close = text.indexOf('}}', cursor);
        const nextOpen = text.indexOf('{{', cursor);
        this.tokenEnd = close >= 0 && (nextOpen < 0 || close < nextOpen) ? close + 2 : cursor;
        const expression = before.slice(start + 2).trim();
        const query = expression.toLocaleLowerCase();
        const wrap = value => '{{ ' + value + ' }}';
        const root = expression.match(/^\$\(\s*(["']?)([^"']*)$/u);
        const customField = expression.match(/^(.*\.cf)\(\s*(\d*)$/u);
        const member = expression.match(/^(.*)\.([A-Za-z]*)$/u);
        let candidates;
        if (root) {
            // Complete the node first, instead of flooding the list with its children.
            candidates = this.sources.filter(source => source.id !== '$vars' && source.name.toLocaleLowerCase().startsWith(root[2].toLocaleLowerCase()))
                .map(source => ({source: source.name, label: 'Результат ноды', expression: wrap('$(' + JSON.stringify(source.name) + ')')}));
        } else if (customField) {
            const aliases = {lead: 'leads', contact: 'contacts', company: 'companies', customer: 'customers'};
            const entity = aliases[customField[1].slice(0, -3)];
            const labels = {leads: 'Сделка', contacts: 'Контакт', companies: 'Компания', customers: 'Покупатель'};
            // Replace the argument only: keep the selected source and any following property.
            this.tokenStart = cursor - customField[2].length;
            const tail = text.slice(cursor).match(/^\d*\s*\)?/u)?.[0] ?? '';
            this.tokenEnd = cursor + tail.length;
            const closing = close >= 0 && (nextOpen < 0 || close < nextOpen) ? '' : ' }}';
            candidates = this.customFields.filter(field => (!entity || entity === field.entity) && String(field.id).startsWith(customField[2]))
                .map(field => ({source: labels[field.entity] || 'Поле', label: field.label + ' · ID ' + field.id,
                    expression: wrap(customField[1] + '(' + field.id + ')'), replacement: field.id + ')' + closing}));
        } else {
            const normalized = value => value.replace(/\$\('([^']*)'\)/gu, (_, name) => '$(' + JSON.stringify(name) + ')').toLocaleLowerCase();
            const search = normalized(query);
            candidates = this.sources.flatMap(source => (source.fields || []).map(item => ({...item, source: source.name})))
                .filter(item => [item.expression, item.label, item.path, item.source].some(value => normalized(String(value ?? '')).includes(search)));
            if (member && /^(\$\(|\$json\b|lead\b|contact\b|company\b|customer\b)/u.test(member[1])) {
                const functions = [
                    {name: 'cf', suffix: 'cf()', label: 'Поле по ID: cf(123)', argument: true},
                    {name: 'first', suffix: 'first()', label: 'Первый элемент'},
                    {name: 'last', suffix: 'last()', label: 'Последний элемент'},
                ].filter(item => item.name.startsWith(member[2]));
                candidates = [...functions.map(item => ({source: 'Функция', label: item.label,
                    expression: wrap(member[1] + '.' + item.suffix),
                    caret: item.argument ? ('{{ ' + member[1] + '.cf(').length : undefined})), ...candidates];
            }
        }
        const seen = new Set();
        this.suggestions = candidates
            .filter(item => item.expression && !seen.has(item.expression) && seen.add(item.expression))
            .slice(0, 8);
        this.suggestionIndex = 0;
    },
    chooseSuggestion(item) {
        const field = this.$refs.value;
        const replacement = item.replacement ?? item.expression;
        const cursor = this.tokenStart + (item.caret ?? (item.replacement === undefined ? replacement.length - 3 : replacement.length));
        const value = field.value.slice(0, this.tokenStart) + replacement + field.value.slice(this.tokenEnd);
        this.state = value;
        this.mode = 'expression';
        this.suggestions = [];
        this.$nextTick(() => {
            field.focus();
            field.setSelectionRange(cursor, cursor);
            field.dispatchEvent(new Event('change', {bubbles: true}));
            if (item.caret !== undefined) this.suggest({target: field});
        });
    },
    suggestionKey(event) {
        if (!this.suggestions.length) return;
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); this.suggestions = []; }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            this.suggestionIndex = (this.suggestionIndex + (event.key === 'ArrowDown' ? 1 : -1) + this.suggestions.length) % this.suggestions.length;
        }
        if (event.key === 'Enter' || event.key === 'Tab') { event.preventDefault(); event.stopPropagation(); this.chooseSuggestion(this.suggestions[this.suggestionIndex]); }
    },
});

window.workflowVariableBrowser = (types) => ({
    types, type: '', query: '', copied: '', selected: null, active: null,
    get items() {
        const rows = this.selected
            ? this.selected.options.map(option => ({id: String(option.id), value: '', label: option.name, options: []}))
            : (this.types[this.type] || []);
        return rows.filter(item => (item.label + ' ' + (item.id || '') + ' ' + (item.value || '')).toLocaleLowerCase('ru').includes(this.query.toLocaleLowerCase('ru')));
    },
    get visibleItems() { return this.items; },
    reset() { this.selected = null; this.active = null; this.query = ''; this.copied = ''; },
    showDetails(item) { this.active = item; },
    showOptions(item) { this.selected = item; this.active = null; this.query = ''; },
    async copy(value) {
        try {
            await navigator.clipboard.writeText(value);
            this.copied = value;
            window.setTimeout(() => { if (this.copied === value) this.copied = ''; }, 1400);
        } catch { this.copied = ''; }
    },
});

window.lockCleverSidebarCollapsed = () => {
    const sidebar = window.Alpine?.store?.('sidebar');

    if (!sidebar) {
        return false;
    }

    const forceClosed = () => {
        sidebar.isOpen = false;
        sidebar.isOpenDesktop = false;
    };

    forceClosed();
    sidebar.open = forceClosed;

    return true;
};

window.scheduleCleverSidebarLock = (attempt = 0) => {
    window.setTimeout(() => {
        if (window.lockCleverSidebarCollapsed()) {
            return;
        }

        if (attempt < 20) {
            window.scheduleCleverSidebarLock(attempt + 1);
        }
    }, attempt === 0 ? 0 : 50);
};

document.addEventListener('alpine:init', () => {
    window.scheduleCleverSidebarLock();
});

document.addEventListener('DOMContentLoaded', () => {
    window.scheduleCleverSidebarLock();
});

document.addEventListener('livewire:navigated', () => {
    window.scheduleCleverSidebarLock();
});

window.addEventListener('resize', () => {
    window.lockCleverSidebarCollapsed?.();
});
