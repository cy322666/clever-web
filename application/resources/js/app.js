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
            const style = getComputedStyle(field);
            Object.assign(state.layer.style, {left:field.offsetLeft+'px',top:field.offsetTop+'px',width:(field.clientWidth + parseFloat(style.borderLeftWidth || 0) + parseFloat(style.borderRightWidth || 0))+'px',height:(field.clientHeight + parseFloat(style.borderTopWidth || 0) + parseFloat(style.borderBottomWidth || 0))+'px',font:style.font,letterSpacing:style.letterSpacing,lineHeight:style.lineHeight,border:style.borderWidth+' solid transparent',padding:style.padding});
            state.text.style.whiteSpace = field.tagName === 'TEXTAREA' ? 'pre-wrap' : 'pre';
            state.text.style.overflowWrap = field.tagName === 'TEXTAREA' ? 'break-word' : 'normal';
            if (state.value !== field.value) {
                state.text.replaceChildren(...parts.map(part => { const span = document.createElement('span'); span.textContent = part.text; if (part.variable) span.className = 'workflow-expression-token'; return span; }));
                state.text.append(document.createTextNode('\u200b'));
                state.value = field.value;
            }
            if (!field.classList.contains('workflow-expression-painted')) field.classList.add('workflow-expression-painted');
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

window.workflowWorkbench = () => ({
    get hasUnsavedChanges() {
        const saved = this.$wire?.savedWorkflowEditorState;
        if (!saved) return true;
        const state = {
            name: this.$wire.data?.name ?? '',
            group_name: this.$wire.data?.group_name ?? null,
            definition: {...this.$wire.definition, trigger: this.$wire.trigger, actions: this.$wire.workflowActions},
        };
        // Ignore object key ordering, but preserve action/branch order and values.
        const stable = value => Array.isArray(value) ? value.map(stable)
            : value && typeof value === 'object' ? Object.fromEntries(Object.keys(value).sort().map(key => [key, stable(value[key])])) : value;
        return JSON.stringify(stable(state)) !== JSON.stringify(stable(saved));
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

window.workflowNodeCanvas = (layoutKey = 'clever.workflow.layout.v2:draft', initialLayout = null) => ({
    layoutKey,
    initialLayout,
    positions: {},
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
    pasteCount: 0,
    pasting: false,
    executionState: null,
    connecting: null,
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
    initialized: false,

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
            if (!actual[id]) return;
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
                    this.pasteCount = 0;
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
                const shift = 80 * ++this.pasteCount;
                const desired = Object.fromEntries(Object.entries(map).filter(([id]) => this.clipboardPositions[id]).map(([oldId, newId]) => [newId, {
                    x:this.clipboardPositions[oldId].x+shift, y:this.clipboardPositions[oldId].y+shift,
                }]));
                this.applyNodePositions();
                this.restoreCardPositions(desired);
                this.selectedNodeIds = Object.values(map);
                this.applyNodeSelection();
                this.scheduleGraphRefreshAfterPaint();
            } finally { this.pasting = false; }
        }
    },

    captureInsertion(sourceId, targetId, dropPosition = null) {
        const stage = this.$refs.stage;
        if (!stage) return;
        const nodes = this.cardPositions();
        const edges = Array.from(stage.querySelectorAll('[data-workflow-edge-target]')).map(e => ({source:e.dataset.workflowEdgeSource,target:e.dataset.workflowEdgeTarget}));
        this.pendingInsertion = {sourceId,targetId,nodes,edges,dropPosition};
    },

    placeInsertedNode(detail) {
        const before = this.pendingInsertion;
        this.pendingInsertion = null;
        if (!before || before.sourceId !== detail.sourceId || before.targetId !== detail.targetId) return;
        this.$nextTick(() => window.requestAnimationFrame(() => {
            const source = before.nodes[detail.sourceId];
            if (!source) return;
            const desired = {...before.nodes};
            const inserted = {x:source.x+192,y:before.nodes[detail.targetId]?.y ?? source.y+(detail.sourcePort === 'yes' ? -180 : detail.sourcePort === 'no' ? 180 : 0)};
            if (before.dropPosition) {
                const node = this.nodeElements().find(node => node.dataset.workflowNodeId === detail.nodeId);
                const box = node?.querySelector('[data-workflow-node-card]')?.getBoundingClientRect();
                inserted.x = before.dropPosition.x - (box?.width ?? 96 * this.scale) / this.scale / 2;
                inserted.y = before.dropPosition.y - (box?.height ?? 96 * this.scale) / this.scale / 2;
            }
            const downstream = new Set();
            const queue = detail.targetId ? [detail.targetId] : [];
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
            desired[detail.nodeId] = inserted;
            this.restoreCardPositions(desired);
            this.scheduleGraphRefreshAfterPaint();
        }));
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
        window.dispatchEvent(new CustomEvent('workflow-node-library-open', {detail: {mode: 'action'}}));
        this.$wire.openAddActionOnConnection(sourceId, sourcePort, targetId);
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
        window.dispatchEvent(new CustomEvent('workflow-node-library-open', {detail: {mode: 'action'}}));
        this.$wire.openAddActionOnConnection(connection.sourceId, connection.sourcePort, connection.replaceTarget ?? null);
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
        const connection = this.connecting;
        this.cancelConnection();
        await this.$wire.connectWorkflowNodes(connection.sourceId, connection.sourcePort, targetId, connection.replaceTarget);
        this.scheduleGraphRefreshAfterPaint();
    },
    cancelConnection() { this.connecting = null; this.pendingEdgeDrag = null; this.connectionCursor = null; this.connectionDragOrigin = null; this.selectedEdge = null; this.scheduleGraphRefresh(); },
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
        this.scheduleGraphRefreshAfterPaint();
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
        let nodes = this.initialLayout;
        try {
            if (nodes === null) {
                const stored = JSON.parse(window.localStorage.getItem(this.layoutKey) || '{}');
                nodes = stored?.version === 1 ? stored.nodes : {};
            }
        } catch { /* Use the saved layout when browser storage is unavailable. */ }
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
        this.$wire?.$set?.('definition.canvas_layout', nodes, false);
        try {
            window.localStorage.setItem(this.layoutKey, JSON.stringify({version: 1, nodes}));
        } catch { /* Server saving still works when browser storage is unavailable. */ }
        this.positions = nodes;
    },

    resetNodeLayout() {
        const controls = this.$refs.stage?.querySelector('.workflow-node-edge-controls') || this.$refs.edgeControls;
        const edges = Array.from(controls?.querySelectorAll('[data-workflow-edge-target]') || []).map(el => ({
            source: el.dataset.workflowEdgeSource, target: el.dataset.workflowEdgeTarget, port: el.dataset.workflowEdgePort,
        }));
        const desired = this.graphLayout(this.nodeElements().map(node => node.dataset.workflowNodeId), edges);
        if (!desired) return; // Never destroy a saved layout for an invalid cyclic graph.
        this.restoreCardPositions(desired);
        this.scheduleGraphRefreshAfterPaint();
        this.$nextTick(() => this.fitView());
    },

    graphLayout(ids, edges) {
        const nodes = new Map(ids.map(id => [id, {id, incoming: [], outgoing: [], rank: 0, children: []}]));
        const seen = new Set();
        edges.forEach(edge => {
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
        const portOrder = {yes: 0, output: 1, no: 2};
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
        return Array.from(this.$refs.stage?.querySelectorAll('[data-workflow-node-id]') || []);
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
        if (!completed(target)) return false;
        if (edge.sourceId.startsWith('trigger')) {
            const starts = this.nodeElements().filter(node => node.dataset.workflowNodeId.startsWith('trigger'));
            return edge.sourceId === (target.input?._workflow_start_node_id || this.executionState?.start_node_id || (starts.length === 1 ? starts[0].dataset.workflowNodeId : null));
        }
        if (!completed(source)) return false;
        if (edge.sourcePort === 'yes' || edge.sourcePort === 'no') {
            const passed = source.output?.passed ?? source.condition_result;
            if (passed === undefined) return false;
            return edge.sourcePort === (passed === true || passed === 1 || passed === 'true' ? 'yes' : 'no');
        }
        return true;
    },

    applyExecutionState() {
        if (!this.executionState) return;
        const results = new Map((this.executionState.results || []).map((r) => ['action:' + r.id, r]));
        this.nodeElements().forEach((node) => {
            const card = node.querySelector('[data-workflow-node-card]');
            if (!card) return;
            const result = results.get(node.dataset.workflowNodeId);
            card.dataset.executionStatus = result?.status || (node.dataset.workflowNodeId === 'action:' + this.executionState.next_id ? 'next' : 'pending');
            const badge = card.querySelector('[data-workflow-node-result]');
            if (badge) {
                badge.hidden = !result;
                const sequence = String(result?.sequence || '');
                if (badge.textContent !== sequence) badge.textContent = sequence;
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
                'data-workflow-edge-source', 'data-workflow-edge-port', 'data-workflow-edge-target'],
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
        const elements = new Set(this.$refs.stage?.querySelectorAll('[data-workflow-node-card], .workflow-node-port') || []);
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
        if (this.draggingNodeId !== null || this.selectionOrigin || this.pendingEdgeDrag) return;
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
        if (this.connecting) return;
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
        this.nodeStartX = Number(position.x) || 0;
        this.nodeStartY = Number(position.y) || 0;
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
            this.connectionCursor = {x: event.clientX, y: event.clientY};
            this.scheduleGraphRefresh();
            return;
        }
        if (this.draggingNodeId !== null && event.pointerId === this.pointerId) {
            const deltaX = (event.clientX - this.pointerStartX) / this.scale;
            const deltaY = (event.clientY - this.pointerStartY) / this.scale;

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

            // Compare numeric coordinates: browsers normalize CSS decimal precision.
            if (Math.abs(parseFloat(button.style.left) - x) > 0.01 || !button.style.left) button.style.left = `${x}px`;
            if (Math.abs(parseFloat(button.style.top) - y) > 0.01 || !button.style.top) button.style.top = `${y}px`;
            if (button.style.visibility !== 'visible') button.style.visibility = 'visible';
            positionedControls.add(button);
            const remove = button.nextElementSibling;
            if (remove?.hasAttribute('data-workflow-edge-delete')) {
                if (Math.abs(parseFloat(remove.style.left) - (x + 28)) > 0.01 || !remove.style.left) remove.style.left = `${x + 28}px`;
                if (Math.abs(parseFloat(remove.style.top) - y) > 0.01 || !remove.style.top) remove.style.top = `${y}px`;
                if (remove.style.visibility !== 'visible') remove.style.visibility = 'visible';
            }
        };
        const sourceSelector = (port) => port === 'yes'
            ? '.workflow-node-port--output-yes'
            : port === 'no'
                ? '.workflow-node-port--output-no'
                : '.workflow-node-port--output:not(.workflow-node-port--output-yes):not(.workflow-node-port--output-no)';

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
            const distance = Math.hypot(endX - startX, endY - startY);
            const curve = Math.min(240, Math.max(48, distance * 0.38));
            const sourceVector = portVector(sourcePortElement ? sourceRect : null, sourceCardRect, {x: 1, y: 0});
            const targetVector = portVector(targetPortElement ? targetRect : null, targetCardRect, {x: -1, y: 0});
            const sourceControlX = startX + sourceVector.x * curve;
            const sourceControlY = startY + sourceVector.y * curve;
            const targetControlX = endX + targetVector.x * curve;
            const targetControlY = endY + targetVector.y * curve;

            paths.push({
                d: `M ${startX} ${startY} C ${sourceControlX} ${sourceControlY}, ${targetControlX} ${targetControlY}, ${endX} ${endY}`,
                port: sourcePort,
                active: button.dataset.workflowEdgeActive,
                edge: {sourceId, sourcePort, targetId: button.dataset.workflowEdgeTarget},
                control: button,
            });
            positionControl(
                button,
                (startX + 3 * sourceControlX + 3 * targetControlX + endX) / 8,
                (startY + 3 * sourceControlY + 3 * targetControlY + endY) / 8,
            );
            maxX = Math.max(maxX, startX, endX);
            maxY = Math.max(maxY, startY, endY);
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
            const buttonY = startY + (port === 'yes' ? -44 : port === 'no' ? 44 : 0);
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
            yes: '#22c55e',
            no: '#ef4444',
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

        const edgePaths = paths.flatMap(({d, port, active, edge, preview, control}) => {
            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            const modifier = port === 'yes' || port === 'no' ? ` workflow-node-edge--${port}` : '';
            const completed = !preview && this.edgeCompleted(edge, active);
            const marker = completed ? 'completed' : port === 'yes' || port === 'no' ? port : 'default';

            path.setAttribute('d', d);
            path.setAttribute('class', `workflow-node-edge${modifier}${completed ? ' workflow-node-edge--completed' : ''}`);
            path.setAttribute('marker-end', `url(#workflow-edge-arrow-${marker})`);
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
                    if (edge.targetId) this.beginEdgeReconnect(edge, event);
                    else this.startConnection(edge.sourceId, edge.sourcePort, event);
                });
                hit.addEventListener('click', (event) => event.stopPropagation());
                if (edge.targetId && control) {
                    hit.addEventListener('pointerenter', () => this.showEdgeControls(control));
                    hit.addEventListener('pointerleave', () => this.hideEdgeControls(control));
                }
                if (this.selectedEdge && Object.keys(edge).every((key) => edge[key] === this.selectedEdge[key])) path.classList.add('is-selected');
                return [hit, path];
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

window.workflowExpressionPicker = (sources) => ({
    sources, selectedId: sources.findLast(source => source.available)?.id ?? sources.at(-1)?.id ?? '', limit: 30,
    query: '',
    field: null, fieldLabel: '', preview: null, expanded: [], collapsedJson: [],
    get selectedSource() { return this.sources.find(source => source.id === this.selectedId); },
    friendlyLabel(item) {
        const names = {id:'ID', name:'Название', price:'Бюджет', responsible_user_id:'Ответственный', status_id:'Этап сделки', pipeline_id:'Воронка',
            entity_id:'ID сущности', entity_type:'Тип сущности', first_name:'Имя', last_name:'Фамилия', text:'Текст', passed:'Условие выполнено',
            count:'Количество результатов', has_more:'Есть следующая страница', next_page:'Следующая страница',
            created_at:'Дата создания', updated_at:'Дата изменения', complete_till:'Срок задачи', is_completed:'Задача выполнена',
            lead:'Сделка', contact:'Контакт', company:'Компания', customer:'Покупатель', items:'Найденные записи', phone:'Телефон', email:'Email'};
        const path = item.path || '';
        const custom = path.match(/^(.*\.(?:custom_fields_values|custom_fields)\[\d+\])/);
        if (custom) {
            const fields = this.selectedSource?.fields ?? [];
            const name = fields.find(f => f.path === custom[1]+'.field_name' || f.path === custom[1]+'.name')?.value;
            const id = fields.find(f => f.path === custom[1]+'.field_id' || f.path === custom[1]+'.id')?.value;
            return (name || 'Поле' + (id ? ' #'+id : '')) + (item.label === 'value' ? ' · значение' : ' · '+(names[item.label] || item.label));
        }
        const entity = path.match(/^\.(lead|contact|company|customer)\./)?.[1];
        const index = path.match(/\[(\d+)\]/)?.[1];
        return (entity ? names[entity]+' → ' : '') + (names[item.label] || item.label) + (index !== undefined ? ' · запись '+(Number(index)+1) : '');
    },
    get businessFields() {
        const query = this.query.toLocaleLowerCase('ru');
        return (this.selectedSource?.fields ?? []).filter(item => !['array','object'].includes(item.type))
            .filter(item => !/\.(webhook|headers|payload|account|_links)(\.|\[|$)/.test(item.path || ''))
            .filter(item => !/\.(field_id|field_name|field_type|enum_id|enum_code|request_id|_workflow_start_node_id|entity)$/.test(item.path || ''))
            .filter(item => (this.friendlyLabel(item)+' '+item.path).toLocaleLowerCase('ru').includes(query));
    },
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
        if (!field?.matches?.('input:not([type=hidden]):not([type=search]):not([type=checkbox]):not([type=radio]):not([type=number]), textarea') || field.closest('.workflow-expression-picker') || field.readOnly || field.disabled || !this.$el.closest('.fi-modal-window')?.contains(field)) return;
        // Select dropdowns contain text search inputs, but those are not configuration values.
        if (!field.hasAttribute('data-workflow-value-input') && !Array.from(field.attributes).some((attribute) => attribute.name.startsWith('wire:model'))) return;
        this.field = field;
        field.closest('.workflow-value-field')?.dispatchEvent(new CustomEvent('workflow-expression-suggestions', {detail: {sources: this.sources}}));
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
        this.$el?.dispatchEvent?.(new CustomEvent('workflow-close-sources', {bubbles:true}));
    },
    updatePreview(event) { if (event.target === this.field) this.refreshPreview(); },
    refreshPreview() {
        if (!this.field?.value.includes('{{')) { this.preview = null; return; }
        const fields = this.sources.flatMap((source) => source.fields);
        this.preview = this.field.value.replace(/\{\{\s*([^{}]+?)\s*\}\}/g, (full, expression) => {
            const item = fields.find((item) => item.expression.replace(/^\{\{\s*|\s*\}\}$/g, '') === expression.trim());
            return item?.available ? this.valueText(item.value) : '⟨нет данных этого шага⟩';
        });
    },
});

window.workflowValueField = (state) => ({
    state, mode: 'value', sources: [], suggestions: [], suggestionIndex: 0, tokenStart: 0, tokenEnd: 0,
    init() { if (typeof this.state === 'boolean') this.state = this.state ? '1' : '0'; this.mode = typeof this.state === 'string' && this.state.includes('{{') ? 'expression' : 'value'; },
    get binding() {
        if (typeof this.state !== 'string') return null;
        for (const source of this.sources) {
            const item = (source.fields || []).find(item => item.expression === this.state.trim());
            if (item) return {label: source.name+' → '+window.workflowExpressionPicker([source]).friendlyLabel(item),
                preview: item.available ? 'Пример: '+String(typeof item.value === 'object' ? JSON.stringify(item.value) : item.value).slice(0,120) : 'Структура без примера. Выполните предыдущую ноду.'};
        }
        return null;
    },
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
        const query = before.slice(start + 2).trim().toLocaleLowerCase();
        const seen = new Set();
        this.suggestions = this.sources.flatMap(source => (source.fields || []).map(item => ({...item, source: source.name})))
            .filter(item => item.expression && !seen.has(item.expression) && seen.add(item.expression))
            .filter(item => [item.expression, item.label, item.path, item.source].join(' ').toLocaleLowerCase().includes(query))
            .slice(0, 8);
        this.suggestionIndex = 0;
    },
    chooseSuggestion(item) {
        const field = this.$refs.value;
        const value = field.value.slice(0, this.tokenStart) + item.expression + field.value.slice(this.tokenEnd);
        this.state = value;
        this.mode = 'expression';
        this.suggestions = [];
        this.$nextTick(() => {
            field.focus();
            const cursor = this.tokenStart + item.expression.length;
            field.setSelectionRange(cursor, cursor);
            field.dispatchEvent(new Event('change', {bubbles: true}));
        });
    },
    suggestionKey(event) {
        if (!this.suggestions.length) return;
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); this.suggestions = []; }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            this.suggestionIndex = (this.suggestionIndex + (event.key === 'ArrowDown' ? 1 : -1) + this.suggestions.length) % this.suggestions.length;
        }
        if (event.key === 'Enter') { event.preventDefault(); event.stopPropagation(); this.chooseSuggestion(this.suggestions[this.suggestionIndex]); }
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
