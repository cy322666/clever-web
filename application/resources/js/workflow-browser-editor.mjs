import { BrowserWorkflowDocument, clone, getPath, setPath, ports, collectionItemFields, filterItemFields, applyTriggerDisplayInput } from './workflow-browser-document.mjs';
import { nodeFields } from './workflow-browser-fields.mjs';
import '../css/workflow-browser-editor.css';

const element = (tag, className = '', text = '') => {
    const el = document.createElement(tag);
    el.className = className;
    el.textContent = text;
    return el;
};

export function createBrowserEditor(boot) {
    const geometry = window.workflowNodeCanvas(
        'clever.workflow.browser:' + (boot.document.id || 'draft'),
        boot.document.definition.canvas_positions || {},
        boot.document.definition.canvas_edge_routes || {},
    );
    // Restore the adapter getter after copying geometry; Alpine injects $wire later.
    return Object.defineProperties(
        {
            ...geometry,
            machine: new BrowserWorkflowDocument(boot.document),
            catalog: boot.actions,
            triggers: boot.triggers,
            references: boot.references,
            operations: boot.operations,
            readLibrary: boot.readLibrary,
            folders: boot.folders,
            version: boot.version,
            baseUrl: boot.baseUrl,
            csrf: boot.csrf,
            listUrl: boot.listUrl,
            codeEditorUrl: boot.codeEditorUrl,
            historyUrl: boot.historyUrl,
            webhookUrl: boot.webhookUrl,
            selectedId: null,
            inspectorTab: 'settings',
            libraryOpen: false,
            librarySearch: '',
            libraryMode: 'all',
            saving: false,
            renameTimer: null,
            renamePending: null,
            renameTask: Promise.resolve(),
            renameQueued: 0,
            savedNotice: false,
            notice: '',
            error: '',
            fieldErrors: {},
            noticeTimer: null,
            running: false,
            runningId: null,
            debugOpen: false,
            debugInput: '{}',
            debugRawInput: null,
            debugLoadedInputSnapshot: null,
            debugReal: false,
            debugPlaying: false,
            debugSession: null,
            debugState: { results: [] },
            debugStartNodeId: 'trigger',
            lastFocusedField: null,
            expressionOpen: false,
            descriptionOpen: false,
            settingsOpen: false,
            pendingAdapter: {},
            destroyed: false,
            headerModes: {},
            referencesOpen: false,
            referenceEntity: 'leads',
            referenceSearch: '',
            get doc() {
                return this.machine.value;
            },
            get hasUnsavedChanges() {
                return this.machine.dirty;
            },
            get currentNode() {
                return this.machine.node(this.selectedId);
            },
            get visibleNodes() {
                return this.machine.nodes();
            },
            get currentResult() {
                return (
                    [...(this.debugState.results || [])]
                        .reverse()
                        .find((result) => 'action:' + result.id === this.selectedId) || null
                );
            },

            mountEditor() {
                const editor = this;
                this.clientWire = {
                    get definition() {
                        return editor.doc.definition;
                    },
                    get workflowActions() {
                        return editor.doc.definition.actions;
                    },
                    get $id() {
                        return 'browser:' + editor.doc.id;
                    },
                    $set(path, value) {
                        if (path.startsWith('definition.') || path === 'workflowActions') {
                            const key = path === 'workflowActions' ? 'actions' : path.slice(11);
                            if (JSON.stringify(getPath(editor.doc.definition, key)) === JSON.stringify(value))
                                return;
                            editor.machine.checkpoint();
                            setPath(editor.doc.definition, key, value);
                        } else editor.pendingAdapter[path] = value;
                    },
                    async connectWorkflowNodes(...args) {
                        editor.machine.connect(...args);
                        editor.graphChanged();
                    },
                    async disconnectWorkflowNodes(...args) {
                        editor.machine.disconnect(...args);
                        editor.graphChanged();
                    },
                    async copyWorkflowNodes(ids) {
                        return editor.machine.copy(ids);
                    },
                    async pasteWorkflowNodes() {
                        const map = editor.machine.paste();
                        editor.graphChanged();
                        return map;
                    },
                };
                this.initializeCanvas();
                if (!this.doc.definition.canvas_positions) this.$nextTick(() => this.resetNodeLayout());
                this.syncBrowserHeight();
                this.beforeLeave = (event) => {
                    if (this.hasUnsavedChanges) {
                        event.preventDefault();
                        event.returnValue = '';
                    }
                };
                window.addEventListener('beforeunload', this.beforeLeave);
            },
            destroy() {
                this.destroyed = true;
                this.debugPlaying = false;
                geometry.destroy.call(this);
                window.removeEventListener('beforeunload', this.beforeLeave);
                clearTimeout(this.noticeTimer);
                clearTimeout(this.renameTimer);
                this.codeEditorCleanup?.();
            },
            startIds() {
                return this.machine.starts().map((node) => node.id);
            },
            referenceFields() {
                const query = this.referenceSearch.toLocaleLowerCase('ru');
                return (this.references.fields || []).filter(
                    (field) =>
                        field.entity === this.referenceEntity &&
                        (field.name + ' ' + field.id).toLocaleLowerCase('ru').includes(query),
                );
            },
            referenceExpression(field) {
                return (
                    '{{ ' +
                    ({ leads: 'lead', contacts: 'contact', companies: 'company', customers: 'customer' }[
                        field.entity
                    ] || '$json') +
                    '.cf(' +
                    field.id +
                    ') }}'
                );
            },
            nodePorts(node) {
                return node.id.startsWith('trigger') ? ['output'] : ports(node);
            },
            portLabel(port) {
                return { yes: 'Да', no: 'Нет', each: 'Каждый элемент', done: 'Готово' }[port] || '';
            },
            metadata(node) {
                return (
                    (node.id?.startsWith('trigger') ? this.triggers : this.catalog)[node.type] || {
                        name: node.type,
                        appearance: { family: 'neutral', tone: 'neutral' },
                        icon: '<span>⋯</span>',
                    }
                );
            },
            nodeName(node) {
                return node?.name || (node ? this.metadata(node).name : '');
            },
            subtitle(node) {
                return node.disabled
                    ? 'Выключено'
                    : node.id?.startsWith('trigger')
                      ? 'Запуск'
                      : this.metadata(node).category || 'Действие';
            },
            syncBrowserHeight() {
                const root = this.$refs.browserEditor;
                root.style.setProperty(
                    '--workflow-page-top',
                    Math.max(0, root.getBoundingClientRect().top) + 'px',
                );
            },
            openNode(id) {
                if (this.connecting) return;
                this.error = '';
                this.selectedId = id;
                if (id.startsWith('trigger') && this.debugStartNodeId !== id) {
                    this.debugStartNodeId = id;
                    this.clearDebugInput();
                }
                this.selectedNodeIds = [id];
                this.inspectorTab = 'settings';
                this.libraryOpen = false;
                // Alpine's $el can be the clicked card; reserve space on the stable editor root.
                this.$refs.browserEditor.toggleAttribute('data-workflow-inspector-open', true);
                this.applyNodeSelection();
                this.$nextTick(() => this.renderInspector());
            },
            closeInspector() {
                this.codeEditorCleanup?.();
                this.codeEditorCleanup = null;
                this.selectedId = null;
                this.expressionOpen = false;
                this.$refs.browserEditor.removeAttribute('data-workflow-inspector-open');
                this.$nextTick(() => this.scheduleGraphRefreshAfterPaint());
            },
            switchTab(tab) {
                this.inspectorTab = tab;
                this.$nextTick(() => this.renderInspector());
            },
            renameCurrent(value) {
                if (!this.selectedId || !String(value).trim()) return;
                try {
                    if (this.renamePending && this.renamePending.id !== this.selectedId) this.flushRename(true);
                    this.machine.rename(this.selectedId, value);
                    this.error = '';
                    this.renamePending = { id: this.selectedId, name: this.currentNode.name };
                    clearTimeout(this.renameTimer);
                    this.renameTimer = setTimeout(() => this.flushRename(true), 400);
                } catch (error) {
                    this.error = error.message;
                }
            },
            finishRename(input) {
                if (!String(input.value).trim() || input.value.trim() !== this.currentNode?.name)
                    input.value = this.currentNode?.name || '';
                this.flushRename(true);
            },
            flushRename(autoSaveNew = false) {
                clearTimeout(this.renameTimer);
                if (this.saving) {
                    this.renameTimer = setTimeout(() => this.flushRename(autoSaveNew), 100);
                    return this.renameTask;
                }
                const pending = this.renamePending;
                this.renamePending = null;
                if (!pending) return this.renameTask;
                const persisted = this.doc.id &&
                    new BrowserWorkflowDocument(JSON.parse(this.machine.saved)).node(pending.id);
                if (!persisted) return autoSaveNew ? this.saveDocument() : this.renameTask;
                this.renameQueued++;
                this.renameTask = this.renameTask.then(async () => {
                    const result = await this.request(`/${this.doc.id}/node-name`, {
                        version: this.version,
                        node_id: pending.id,
                        name: pending.name,
                    }, 'PATCH');
                    this.version = result.version;
                    // The acknowledgement advances only the saved baseline. Other local edits stay intact.
                    this.machine.saved = JSON.stringify(new BrowserWorkflowDocument(result.document).value);
                    return true;
                }).catch((error) => {
                    this.error = error.message;
                    return false;
                }).finally(() => {
                    this.renameQueued--;
                });
                return this.renameTask;
            },
            setDocument(path, value) {
                this.machine.checkpoint();
                setPath(this.doc, path, value);
            },
            removeNode(id) {
                this.machine.remove(id);
                delete this.positions[id];
                if (this.selectedId === id) this.closeInspector();
                this.graphChanged();
            },
            toggleNode(id) {
                const node = this.machine.node(id);
                if (node) {
                    this.machine.checkpoint();
                    node.disabled = !node.disabled;
                }
            },
            duplicateNode(id) {
                const points = this.cardPositions();
                this.machine.copy([id]);
                const mapping = this.machine.paste(),
                    next = mapping[id];
                this.$nextTick(() => {
                    this.applyNodePositions();
                    this.restoreCardPositions({
                        ...points,
                        [next]: { x: (points[id]?.x || 0) + 160, y: (points[id]?.y || 0) + 140 },
                    });
                    this.saveNodeLayout();
                    this.graphChanged();
                    this.openNode(next);
                });
            },
            graphChanged() {
                this.$nextTick(() => {
                    this.applyNodePositions();
                    this.observeNodeGeometry();
                    this.scheduleGraphRefreshAfterPaint();
                });
            },
            libraryItems() {
                const items = [];
                if (this.libraryMode !== 'actions' && !this.pendingAdapter.insertConnection)
                    Object.values(this.triggers)
                        .filter((item) => !['date-condition', 'workflow-completed'].includes(item.type))
                        .forEach((item) => items.push({ ...item, trigger: true }));
                if (this.libraryMode !== 'trigger') {
                    Object.values(this.catalog)
                        .filter((item) => item.type !== 'amocrm_read')
                        .forEach((item) => items.push(item));
                    Object.entries(this.readLibrary).forEach(([operation, item]) =>
                        items.push({
                            ...this.catalog.amocrm_read,
                            name: item.node_name || item.name,
                            category: item.group,
                            operation,
                        }),
                    );
                }
                const query = this.librarySearch.toLocaleLowerCase('ru');
                return items.filter((item) =>
                    (item.name + ' ' + item.category).toLocaleLowerCase('ru').includes(query),
                );
            },
            openLibrary(mode = 'all') {
                this.libraryMode = this.pendingAdapter.insertConnection ? 'actions'
                    : ['action', 'actions', 'query', 'service'].includes(mode) ? 'actions' : mode;
                this.libraryOpen = true;
                this.librarySearch = '';
                this.$nextTick(() => this.$refs.librarySearch?.focus());
            },
            insertNode(item) {
                try {
                    const connection = this.pendingAdapter.insertConnection;
                    if (item.trigger && connection) throw new Error('Запуск нельзя добавить внутри ветки.');
                    const id = this.machine.add(item, {
                        trigger: item.trigger,
                        operation: item.operation,
                        connection,
                    });
                    this.pendingAdapter.insertConnection = null;
                    this.libraryOpen = false;
                    this.$nextTick(() => {
                        this.applyNodePositions();
                        this.observeNodeGeometry();
                        const point = this.pendingDropPosition;
                        if (point) {
                            this.restoreCardPositions({
                                ...this.cardPositions(),
                                [id]: {
                                    x: this.snapCoordinate(point.x - 48),
                                    y: this.snapCoordinate(point.y - 48),
                                },
                            });
                            this.pendingDropPosition = null;
                        } else if (this.pendingInsertion && !item.trigger)
                            this.placeInsertedNode({ nodeId: id, ...connection });
                        else this.centerNodeGroup({ [id]: { x: 0, y: 0 } });
                        this.saveNodeLayout();
                        this.scheduleGraphRefreshAfterPaint();
                        this.openNode(id);
                    });
                } catch (error) {
                    this.error = error.message;
                }
            },
            browserShortcut(event) {
                if (event.target.closest('input,textarea,select,[contenteditable="true"]')) return;
                if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
                    event.preventDefault();
                    this.saveDocument();
                    return;
                }
                if ((event.ctrlKey || event.metaKey) && ['z', 'y'].includes(event.key.toLowerCase())) {
                    event.preventDefault();
                    if (this.machine.undo(event.shiftKey || event.key.toLowerCase() === 'y')) {
                        this.positions = clone(this.doc.definition.canvas_positions || {});
                        this.graphChanged();
                        this.renderInspector();
                    }
                    return;
                }
                if (['Delete', 'Backspace'].includes(event.key) && this.selectedNodeIds.length) {
                    event.preventDefault();
                    [...this.selectedNodeIds].forEach((id) => this.removeNode(id));
                    this.clearNodeSelection();
                    return;
                }
                this.clipboardShortcut(event);
            },
            async request(path, body, method = 'POST') {
                const response = await fetch(this.baseUrl + path, {
                    method,
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': this.csrf,
                    },
                    body: JSON.stringify(body),
                });
                const data = await response.json().catch(() => ({
                    message:
                        response.status === 419
                            ? 'Сессия истекла. Откройте редактор заново.'
                            : 'Сервер не вернул ответ.',
                }));
                if (!response.ok) {
                    this.fieldErrors = data.errors || {};
                    throw new Error(
                        Object.values(data.errors || {})
                            .flat()
                            .join('\n') ||
                            data.message ||
                            'Не удалось выполнить действие.',
                    );
                }
                return data;
            },
            async saveDocument(active = null) {
                if (this.saving) return false;
                if (this.renamePending || this.renameQueued) await this.flushRename();
                if (this.saving) return false;
                this.saveNodeLayout();
                const sent = clone(this.doc),
                    revision = this.machine.revision;
                if (active !== null) sent.is_active = active;
                this.saving = true;
                this.error = '';
                this.fieldErrors = {};
                try {
                    const result = await this.request(
                        sent.id ? `/${sent.id}/document` : '/document',
                        { document: sent, version: this.version },
                        sent.id ? 'PUT' : 'POST',
                    );
                    this.version = result.version;
                    this.historyUrl = result.historyUrl;
                    this.webhookUrl = result.webhookUrl;
                    // Do not overwrite edits made while a slow save was in flight.
                    const saved = new BrowserWorkflowDocument(result.document);
                    this.machine.saved = JSON.stringify(saved.value);
                    if (this.machine.revision === revision) {
                        this.machine.value = saved.value;
                        this.$nextTick(() => this.renderInspector());
                    } else {
                        this.doc.id = result.document.id;
                        this.doc.is_active = result.document.is_active;
                    }
                    if (!sent.id) window.history.replaceState({}, '', result.editUrl);
                    this.notify('Сохранено');
                    return true;
                } catch (error) {
                    this.error = error.message;
                    return false;
                } finally {
                    this.saving = false;
                }
            },
            notify(message) {
                this.notice = message;
                clearTimeout(this.noticeTimer);
                this.noticeTimer = setTimeout(() => {
                    this.notice = '';
                }, 1600);
            },
            async copyValue(value) {
                try {
                    await navigator.clipboard.writeText(
                        typeof value === 'string' ? value : JSON.stringify(value),
                    );
                    this.notify('Скопировано');
                } catch {
                    this.error = 'Не удалось скопировать. Выделите текст и нажмите Ctrl+C.';
                }
            },
            async refreshReferences() {
                if (!this.doc.id || this.running) return;
                this.running = true;
                try {
                    this.references = await this.request(`/${this.doc.id}/references`, {
                        refresh: true,
                        type: this.currentNode?.type,
                        config: this.currentNode?.config || {},
                    });
                    this.renderInspector();
                    this.notify('Справочники обновлены');
                } catch (error) {
                    this.error = error.message;
                } finally {
                    this.running = false;
                }
            },
            async duplicateDocument() {
                if (this.saving || !this.doc.id) return;
                this.saveNodeLayout();
                this.saving = true;
                this.error = '';
                try {
                    const document = clone(this.doc);
                    document.name = String(document.name).slice(0, 245) + ' (копия)';
                    const result = await this.request(`/${this.doc.id}/duplicate`, {
                        document,
                        version: this.version,
                    });
                    this.machine = new BrowserWorkflowDocument(result.document);
                    this.version = result.version;
                    this.historyUrl = result.historyUrl;
                    this.webhookUrl = result.webhookUrl;
                    this.$nextTick(() => this.renderInspector());
                    this.debugSession = null;
                    this.debugState = { results: [] };
                    this.setExecutionState(this.debugState);
                    window.history.replaceState({}, '', result.editUrl);
                    this.notify('Копия создана');
                } catch (error) {
                    this.error = error.message;
                } finally {
                    this.saving = false;
                }
            },
            async deleteDocument() {
                if (
                    this.saving ||
                    !this.doc.id ||
                    !window.confirm('Удалить сценарий? Запуски этого сценария будут остановлены.')
                )
                    return;
                this.saving = true;
                try {
                    await this.request(`/${this.doc.id}/document`, { version: this.version }, 'DELETE');
                    this.machine.saved = JSON.stringify(this.doc);
                    window.location.assign(this.listUrl);
                } catch (error) {
                    this.error = error.message;
                    this.saving = false;
                }
            },
            clearDebugInput() {
                this.debugSession = null;
                this.debugRawInput = null;
                this.debugLoadedInputSnapshot = null;
                this.debugInput = '{}';
            },
            async loadDebugInput() {
                if (!this.doc.id || this.running) return;
                try {
                    const result = await this.request(`/${this.doc.id}/debug`, { command: 'input', nodeId: this.debugStartNodeId });
                    this.debugRawInput = result.input;
                    this.debugInput = JSON.stringify(result.display_input ?? result.input, null, 2);
                    this.debugLoadedInputSnapshot = this.debugInput;
                    if (this.selectedId?.startsWith('trigger') && this.inspectorTab === 'input') this.renderInspector();
                } catch (error) {
                    this.error = error.message;
                }
            },
            async runDebug(command = 'start', nodeId = null) {
                if (this.running) return false;
                if (!this.doc.id) {
                    this.error = 'Сначала сохраните сценарий.';
                    return false;
                }
                // A node's Play keeps its meaning: actually execute that one node.
                // Full-flow debugging separately offers simulation and confirmed real execution.
                const real = command === 'node' || this.debugReal;
                if (
                    command !== 'node' &&
                    real &&
                    !this.debugSession &&
                    !window.confirm(
                        'Реально выполнить действия? Они могут изменить данные и отправить сообщения.',
                    )
                )
                    return false;
                this.running = true;
                this.runningId = nodeId;
                this.error = '';
                try {
                    const parsed = JSON.parse(this.debugInput || '{}');
                    if (!parsed || typeof parsed !== 'object')
                        throw new Error('На входе нужен объект или массив JSON.');
                    const triggerInput = this.debugRawInput
                        ? this.debugInput === this.debugLoadedInputSnapshot
                            ? clone(this.debugRawInput)
                            : applyTriggerDisplayInput(this.debugRawInput, parsed)
                        : parsed;
                    const input = {
                        ...triggerInput,
                        _workflow_start_node_id: this.startIds().includes(this.debugStartNodeId)
                            ? this.debugStartNodeId
                            : this.startIds()[0],
                    };
                    const state = await this.request(`/${this.doc.id}/debug`, {
                        command,
                        session: this.debugSession,
                        nodeId,
                        definition: this.doc.definition,
                        input,
                        real,
                    });
                    this.debugSession = state.session;
                    this.debugState = state;
                    this.setExecutionState(state);
                    if (nodeId) {
                        this.selectedId = nodeId;
                        this.inspectorTab = 'output';
                        this.$refs.browserEditor.toggleAttribute('data-workflow-inspector-open', true);
                    }
                    this.$nextTick(() => this.renderInspector());
                    if (state.error) this.error = state.error;
                    return state.status === 'ready';
                } catch (error) {
                    this.error = error.message;
                    return false;
                } finally {
                    this.running = false;
                    this.runningId = null;
                }
            },
            async playDebug() {
                if (this.debugPlaying) {
                    this.debugPlaying = false;
                    return;
                }
                this.debugPlaying = true;
                try {
                    let next = await this.runDebug('start');
                    while (next && this.debugPlaying && !this.destroyed) next = await this.runDebug('step');
                } finally {
                    this.debugPlaying = false;
                }
            },
            exportDocument() {
                const blob = new Blob([JSON.stringify(this.doc, null, 2)], { type: 'application/json' }),
                    url = URL.createObjectURL(blob);
                const link = element('a');
                link.href = url;
                link.download = `flow-${this.doc.id || 'draft'}.json`;
                link.click();
                URL.revokeObjectURL(url);
            },
            async importDocument(file) {
                if (!file) return;
                try {
                    if (file.size > 4 * 1024 * 1024) throw new Error('Файл превышает 4 МБ.');
                    const data = JSON.parse(await file.text()),
                        source = data.definition || data.workflow?.definition || data;
                    if (!Array.isArray(source.actions)) throw new Error('В файле нет сценария.');
                    if (
                        !window.confirm(
                            'Заменить текущую схему данными из файла? Изменения применятся только после сохранения.',
                        )
                    )
                        return;
                    this.machine.checkpoint();
                    // Preserve the current workflow identity/activation, not the imported account's identity.
                    const next = new BrowserWorkflowDocument({ ...this.doc, definition: source });
                    this.machine.value = next.value;
                    this.positions = clone(source.canvas_positions || {});
                    this.closeInspector();
                    this.graphChanged();
                } catch (error) {
                    this.error = error.message;
                }
            },

            renderInspector() {
                const host = this.$refs.nodeFields;
                if (!host || !this.currentNode) return;
                this.codeEditorCleanup?.();
                this.codeEditorCleanup = null;
                host.replaceChildren();
                if (this.inspectorTab !== 'settings') {
                    const result = this.currentResult;
                    const start = this.selectedId.startsWith('trigger');
                    if (!result && !start) {
                        host.append(
                            element('p', 'workflow-browser-empty', 'Нода ещё не выполнялась в этой отладке.'),
                        );
                        return;
                    }
                    let value;
                    if (start) {
                        try {
                            value = JSON.parse(this.debugInput);
                        } catch {
                            value = this.debugInput;
                        }
                    } else
                        value =
                            this.inspectorTab === 'output'
                                ? Object.hasOwn(result, 'display_output')
                                    ? result.display_output
                                    : result.output
                                : Object.hasOwn(result, 'display_input')
                                  ? result.display_input
                                  : result.input;
                    if (result?.error) host.append(element('p', 'workflow-browser-error', result.error));
                    this.renderJson(host, value);
                    return;
                }
                const node = this.currentNode;
                if (node.type === 'generic-webhook') {
                    const button = element(
                        'button',
                        'workflow-browser-webhook',
                        this.webhookUrl || 'URL появится после сохранения',
                    );
                    button.type = 'button';
                    button.addEventListener(
                        'click',
                        () => this.webhookUrl && this.copyValue(this.webhookUrl),
                    );
                    host.append(button);
                }
                const fields = nodeFields(node, this.references, this.operations);
                fields.forEach((spec) => this.renderField(host, node.config, spec, ''));
                // An explicit advanced editor retains every custom/legacy key, including expressions.
                const advanced = element('details', 'workflow-browser-advanced'),
                    summary = element('summary', '', 'Все настройки JSON');
                const editor = element('textarea', 'workflow-browser-input workflow-browser-code');
                editor.rows = 12;
                editor.value = JSON.stringify(node.config, null, 2);
                editor.setAttribute('aria-label', 'Все настройки ноды JSON');
                editor.addEventListener('change', () => {
                    try {
                        const config = JSON.parse(editor.value);
                        if (!config || Array.isArray(config) || typeof config !== 'object')
                            throw new Error('Нужен объект JSON.');
                        this.machine.checkpoint();
                        this.currentNode.config = config;
                        this.renderInspector();
                    } catch (error) {
                        this.error = error.message;
                    }
                });
                advanced.append(summary, editor);
                host.append(advanced);
            },
            renderField(host, scope, spec, prefix) {
                if (spec.visible && !spec.visible(scope)) return;
                const wrapper = element('div', 'workflow-browser-field'),
                    path = prefix + spec.path;
                wrapper.dataset.field = path;
                if (spec.label) {
                    const label = element('label', '', spec.label);
                    label.htmlFor = 'wb-' + path;
                    wrapper.append(label);
                }
                if (spec.headers) {
                    this.renderHeaders(wrapper, scope, spec, prefix);
                    host.append(wrapper);
                    return;
                }
                if (spec.fields) {
                    let rows = getPath(scope, spec.path, []);
                    if (!Array.isArray(rows)) rows = Object.values(rows || {});
                    rows.forEach((row, index) => {
                        const card = element('div', 'workflow-browser-repeater');
                        const remove = element('button', 'workflow-browser-remove', '×');
                        remove.type = 'button';
                        remove.setAttribute('aria-label', 'Удалить строку');
                        remove.addEventListener('click', () => {
                            this.machine.checkpoint();
                            const next = [...rows];
                            next.splice(index, 1);
                            setPath(this.currentNode.config, path, next);
                            this.renderInspector();
                        });
                        card.append(remove);
                        spec.fields.forEach((child) =>
                            this.renderField(card, row, child, path + '.' + index + '.'),
                        );
                        wrapper.append(card);
                    });
                    const add = element('button', 'workflow-browser-button', spec.addLabel);
                    add.type = 'button';
                    add.addEventListener('click', () => {
                        this.machine.checkpoint();
                        setPath(this.currentNode.config, path, [...rows, clone(spec.defaults || {})]);
                        this.renderInspector();
                    });
                    wrapper.append(add);
                    host.append(wrapper);
                    return;
                }
                const value = getPath(scope, spec.path, spec.default ?? ''),
                    options = typeof spec.options === 'function' ? spec.options(scope) : spec.options || {};
                const commit = (value, redraw = false) => {
                    if (spec.preset) {
                        const rows = (this.currentNode.config.parameters ||= []),
                            withRow = rows.find((row) => row.name === 'with');
                        this.machine.checkpoint();
                        if (withRow)
                            withRow.value = [
                                ...new Set(
                                    String(withRow.value || '')
                                        .split(',')
                                        .filter(Boolean)
                                        .concat(value),
                                ),
                            ].join(',');
                        else rows.push({ name: 'with', value });
                        this.renderInspector();
                        return;
                    }
                    this.machine.checkpoint(redraw ? null : this.selectedId + ':' + path);
                    setPath(this.currentNode.config, path, value);
                    if (spec.path === 'operation')
                        this.currentNode.config.body_mode = this.references.query[value]
                            ? 'builder'
                            : 'fields';
                    if (spec.path === 'body_mode' && value === 'builder')
                        for (const [key, fallback] of Object.entries({
                            limit: 50,
                            page: 1,
                            direction: 'asc',
                        }))
                            if (!this.currentNode.config[key]) this.currentNode.config[key] = fallback;
                    if (redraw) this.renderInspector();
                };
                if (spec.buttons) {
                    const group = element('div', 'workflow-browser-options');
                    group.setAttribute('role', 'group');
                    group.setAttribute('aria-label', spec.label || 'Режим');
                    Object.entries(options).forEach(([key, label]) => {
                        const button = element('button', 'workflow-browser-button', label);
                        button.type = 'button';
                        button.setAttribute('aria-pressed', String(String(value) === key));
                        button.addEventListener('click', () => commit(key, true));
                        group.append(button);
                    });
                    wrapper.append(group);
                    host.append(wrapper);
                    return;
                }
                if (spec.boolean) {
                    const input = element('input');
                    input.type = 'checkbox';
                    input.id = 'wb-' + path;
                    input.checked = Boolean(value);
                    input.addEventListener('change', () => commit(input.checked));
                    wrapper.append(input);
                    host.append(wrapper);
                    return;
                }
                const input = element(
                    spec.rows || spec.headers || spec.json ? 'textarea' : 'input',
                    'workflow-browser-input',
                );
                input.id = 'wb-' + path;
                input.setAttribute('aria-label', spec.label || 'Значение условия');
                if (input.tagName === 'TEXTAREA') input.rows = spec.rows || 5;
                else input.type = spec.number ? 'number' : 'text';
                input.value = typeof value === 'object' ? JSON.stringify(value, null, 2) : String(value);
                if (path.includes('javascript') || spec.headers || spec.json || String(value).includes('{{'))
                    input.classList.add('workflow-browser-code');
                input.addEventListener('focus', () => {
                    this.lastFocusedField = { input, path };
                });
                input.addEventListener('input', () => {
                    if (spec.json) return;
                    const next = spec.number && input.value !== '' ? Number(input.value) : input.value;
                    commit(next);
                });
                if (spec.json)
                    input.addEventListener('change', () => {
                        try {
                            commit(JSON.parse(input.value));
                        } catch {
                            this.error = 'Проверьте JSON в поле «' + spec.label + '».';
                        }
                    });
                if (spec.itemField) this.attachFilterAutocomplete(wrapper, input, commit);
                else if (!spec.json && !spec.number && !spec.headers && spec.rows !== 18)
                    this.attachAutocomplete(wrapper, input, commit);
                if (spec.rows === 18) {
                    input.addEventListener('keydown', (event) => {
                        if (event.key === 'Tab') {
                            event.preventDefault();
                            const start = input.selectionStart;
                            input.setRangeText('  ', start, input.selectionEnd, 'end');
                            commit(input.value);
                        }
                    });
                    const format = element('button', 'workflow-browser-button', 'Форматировать');
                    format.type = 'button';
                    format.addEventListener('click', async () => {
                        const source = input.value,
                            nodeId = this.selectedId;
                        format.disabled = true;
                        try {
                            const { formatJavascript } = await import('./workflow-code-format.mjs');
                            const formatted = await formatJavascript(source);
                            if (input.isConnected && this.selectedId === nodeId && input.value === source) {
                                const editor = this.codeEditorView?.();
                                if (editor)
                                    editor.dispatch({
                                        changes: { from: 0, to: editor.state.doc.length, insert: formatted },
                                    });
                                else {
                                    input.value = formatted;
                                    commit(formatted);
                                }
                            }
                        } catch (error) {
                            this.error = 'Код не изменён. ' + error.message;
                        } finally {
                            format.disabled = false;
                        }
                    });
                    wrapper.append(format);
                    this.$nextTick(() => this.mountCodeEditor(wrapper, input, commit));
                }
                const controls = element('div', 'workflow-browser-input-controls');
                const literal = [
                    'operation',
                    'operator',
                    'body_mode',
                    'body_format',
                    'mode',
                    'match',
                    'logic',
                    'parse_mode',
                    'frequency',
                    'day_of_week',
                    'sort',
                    'direction',
                    'credential_id',
                    'entity_source',
                    'source',
                    'field',
                ].includes(spec.path);
                if (Object.keys(options).length) {
                    const select = element('select', 'workflow-browser-input');
                    select.setAttribute('aria-label', spec.label || 'Выбрать значение');
                    const empty = element('option', '', 'Выбрать…');
                    empty.value = '';
                    select.append(empty);
                    Object.entries(options).forEach(([key, label]) => {
                        const option = element('option', '', label);
                        option.value = key;
                        option.selected = String(value) === key;
                        select.append(option);
                    });
                    select.addEventListener('change', () => {
                        if (select.value || !spec.preset) commit(select.value, true);
                    });
                    controls.append(select);
                    // A selection and its raw code are not two different fields.
                    // Show the text input only for expressions/manual IDs absent from the dictionary.
                    input.hidden =
                        literal || spec.preset || Object.hasOwn(options, String(value)) || value === '';
                    if (literal) input.disabled = true;
                }
                // Expressions never alter a hidden copy of the value: both modes share this same field.
                if (!spec.json && !spec.number && !spec.preset && !literal) {
                    const expression = element('button', 'workflow-browser-expression', '{ }');
                    expression.type = 'button';
                    expression.title = 'Вставить переменную';
                    expression.setAttribute('aria-label', 'Вставить переменную');
                    expression.addEventListener('click', () => {
                        if (spec.rows !== 18 || !this.codeEditorView?.()) input.hidden = false;
                        this.lastFocusedField = { input, path };
                        this.expressionOpen = !this.expressionOpen;
                        this.$nextTick(() => this.renderExpressions());
                    });
                    controls.append(expression);
                }
                wrapper.append(controls, input);
                host.append(wrapper);
            },
            renderHeaders(host, scope, spec, prefix) {
                const value = getPath(scope, spec.path, ''),
                    key = this.selectedId + ':' + prefix + spec.path;
                const helper = window.workflowHeadersField(value),
                    rows = helper.parseRows(value);
                const simple =
                    rows && rows.every((row) => row.value === null || typeof row.value !== 'object');
                const mode = this.headerModes[key] || (simple ? 'fields' : 'json'),
                    group = element('div', 'workflow-browser-options');
                for (const [id, label] of [
                    ['fields', 'Поля'],
                    ['json', 'JSON'],
                ]) {
                    const button = element('button', 'workflow-browser-button', label);
                    button.type = 'button';
                    button.setAttribute('aria-pressed', String(mode === id));
                    button.addEventListener('click', () => {
                        if (id === 'fields' && !simple) {
                            this.error = 'Для целой переменной или сложного значения оставьте JSON.';
                            return;
                        }
                        this.headerModes[key] = id;
                        this.renderInspector();
                    });
                    group.append(button);
                }
                host.append(group);
                if (mode === 'json') {
                    this.renderField(host, scope, { ...spec, headers: false, rows: 6, label: '' }, prefix);
                    return;
                }
                const commit = () => {
                    this.machine.checkpoint(key);
                    setPath(
                        this.currentNode.config,
                        prefix + spec.path,
                        rows.map((row) => ({ name: row.name, value: row.value })),
                    );
                };
                rows.forEach((row, index) => {
                    const item = element('div', 'workflow-browser-repeater'),
                        remove = element('button', 'workflow-browser-remove', '×');
                    remove.type = 'button';
                    remove.setAttribute('aria-label', 'Удалить заголовок');
                    remove.addEventListener('click', () => {
                        rows.splice(index, 1);
                        commit();
                        this.renderInspector();
                    });
                    item.append(remove);
                    for (const [column, label] of [
                        ['name', 'Название заголовка'],
                        ['value', 'Значение заголовка'],
                    ]) {
                        const input = element('input', 'workflow-browser-input');
                        input.type = 'text';
                        input.value = row[column] ?? '';
                        input.setAttribute('aria-label', label);
                        input.addEventListener('input', () => {
                            row[column] = input.value;
                            commit();
                        });
                        this.attachAutocomplete(item, input, (next) => {
                            row[column] = next;
                            commit();
                        });
                        item.append(input);
                    }
                    host.append(item);
                });
                const add = element('button', 'workflow-browser-button', 'Добавить заголовок');
                add.type = 'button';
                add.addEventListener('click', () => {
                    rows.push({ name: '', value: '' });
                    commit();
                    this.renderInspector();
                });
                host.append(add);
            },
            async mountCodeEditor(wrapper, input, commit) {
                if (!this.codeEditorUrl) return;
                const nodeId = this.selectedId;
                try {
                    // Reuse Filament's bundled CodeMirror, with a local setter instead of $entangle.
                    const { default: factory } = await import(/* @vite-ignore */ this.codeEditorUrl);
                    if (!input.isConnected || this.selectedId !== nodeId) return;
                    const mount = element('div', 'fi-fo-code-editor workflow-browser-code-editor');
                    wrapper.append(mount);
                    let source = input.value;
                    const widget = factory({
                        canWrap: true,
                        isDisabled: false,
                        isLive: false,
                        isLiveDebounced: false,
                        isLiveOnBlur: false,
                        language: 'javascript',
                        state: source,
                    });
                    Object.defineProperty(widget, 'state', {
                        get: () => source,
                        set: (value) => {
                            source = value;
                            input.value = value;
                            commit(value);
                        },
                        configurable: true,
                    });
                    widget.$refs = { editor: mount };
                    widget.$watch = () => {};
                    widget.$wire = { commit() {}, $commit() {} };
                    widget.init();
                    widget.editor.contentDOM.setAttribute('aria-label', 'JavaScript');
                    input.hidden = true;
                    this.codeEditorView = () => widget.editor;
                    this.codeEditorCleanup = () => {
                        widget.destroy();
                        this.codeEditorView = null;
                    };
                } catch {
                    /* The plain local textarea stays usable if the optional editor fails to load. */
                }
            },
            expressionSources() {
                let count = 0;
                const upstream = new Set(),
                    edges = this.canvasEdges([], this.startIds()).filter(
                        (edge) => edge.targetId && !edge.loopReturn && !edge.loopFeedback,
                    ),
                    pending = [this.selectedId];
                while (pending.length) {
                    const id = pending.pop();
                    for (const edge of edges)
                        if (edge.targetId === id && !upstream.has(edge.sourceId)) {
                            upstream.add(edge.sourceId);
                            pending.push(edge.sourceId);
                        }
                }
                let input = this.currentResult?.display_input;
                try {
                    input ??= JSON.parse(this.debugInput);
                } catch {
                    input = {};
                }
                const nodes = [
                    { id: '$json', name: 'Вход текущей ноды', value: input },
                    ...this.machine
                        .nodes()
                        .filter((node) => node.id !== this.selectedId && upstream.has(node.id)),
                ];
                return nodes.map((node) => {
                    const reference = node.id === '$json' ? '$json' : '$(' + JSON.stringify(node.name) + ')',
                        fields = [];
                    const result = [...(this.debugState.results || [])]
                        .reverse()
                        .find((row) => 'action:' + row.id === node.id);
                    const operation = node.config?.operation;
                    const expected = node.type === 'amocrm_read' && operation?.endsWith('.list')
                        ? Object.keys(this.references.query?.[operation]?.fields || {}).filter((key) => !key.startsWith('custom:'))
                        : node.type === 'amocrm_query_leads'
                          ? ['id', 'name', 'price', 'pipeline_id', 'status_id']
                          : node.type === 'amocrm_contact_leads'
                            ? ['id', 'pipeline_id', 'status_id']
                            : [];
                    const sample = expected.length ? [Object.fromEntries(expected.map((key) => [key, null]))] : null;
                    const value = node.id === '$json' ? node.value : (result?.display_output ?? result?.output ?? sample);
                    const itemFields = collectionItemFields(value);
                    fields.push({ label: node.name, path: '', expression: '{{ ' + reference + ' }}',
                        type: Array.isArray(value) ? 'array' : 'object', item_fields: itemFields });
                    const hasItemsOutput = Array.isArray(result?.output?.items)
                        || ['amocrm_read', 'amocrm_query_leads', 'amocrm_contact_leads', 'workflow_filter_list'].includes(node.type);
                    if (itemFields.length && node.id !== '$json' && hasItemsOutput)
                        fields.push({ label: 'Список элементов', path: '.items', expression: '{{ ' + reference + '.items }}',
                            type: 'array', item_fields: itemFields });
                    const visit = (value, path = '', depth = 0) => {
                        if (!value || typeof value !== 'object' || Array.isArray(value) || depth > 3 || count > 1500) return;
                        Object.entries(value)
                            .slice(0, 40)
                            .forEach(([key, child]) => {
                                if (count++ > 1500) return;
                                const next =
                                    path +
                                    (/^[A-Za-z_$][\w$]*$/.test(key)
                                        ? '.' + key
                                        : '[' + JSON.stringify(key) + ']');
                                fields.push({
                                    label: key,
                                    path: next,
                                    expression: '{{ ' + reference + next + ' }}',
                                    type: Array.isArray(child) ? 'array' : typeof child,
                                    item_fields: collectionItemFields(child),
                                });
                                visit(child, next, depth + 1);
                            });
                    };
                    visit(value);
                    return { id: node.id, name: node.name, fields };
                });
            },
            attachFilterAutocomplete(wrapper, input, commit) {
                const popup = element('div', 'workflow-browser-autocomplete');
                popup.hidden = true;
                let suggestions = [], active = 0;
                const choose = (item) => {
                    input.value = item;
                    commit(item);
                    popup.hidden = true;
                    input.focus();
                };
                const paint = () => {
                    suggestions = filterItemFields(this.expressionSources(), this.currentNode?.config?.items)
                        .filter((key) => key.toLocaleLowerCase().startsWith(input.value.toLocaleLowerCase()))
                        .slice(0, 8);
                    popup.replaceChildren();
                    popup.hidden = !suggestions.length;
                    suggestions.forEach((key, index) => {
                        const button = element('button', '', key);
                        button.type = 'button';
                        button.classList.toggle('is-active', index === active);
                        button.addEventListener('pointerdown', (event) => {
                            event.preventDefault();
                            choose(key);
                        });
                        popup.append(button);
                    });
                };
                input.addEventListener('focus', paint);
                input.addEventListener('input', () => { active = 0; paint(); });
                input.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape') { popup.hidden = true; return; }
                    if (popup.hidden) return;
                    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                        event.preventDefault();
                        active = (active + (event.key === 'ArrowDown' ? 1 : -1) + suggestions.length) % suggestions.length;
                        paint();
                    } else if ((event.key === 'Enter' || event.key === 'Tab') && suggestions[active]) {
                        event.preventDefault();
                        choose(suggestions[active]);
                    }
                });
                input.addEventListener('blur', () => { popup.hidden = true; });
                wrapper.append(popup);
            },
            attachAutocomplete(wrapper, input, commit) {
                if (!window.workflowValueField) return;
                input.setAttribute('data-workflow-value-input', '');
                const helper = window.workflowValueField(input.value),
                    popup = element('div', 'workflow-browser-autocomplete');
                popup.hidden = true;
                Object.defineProperty(helper, 'state', {
                    get: () => input.value,
                    set: (value) => {
                        input.value = value;
                        commit(value);
                    },
                    configurable: true,
                });
                helper.$refs = { value: input };
                helper.$nextTick = (callback) => callback();
                helper.customFields = (this.references.fields || []).map((field) => ({
                    id: field.id,
                    label: field.name,
                    entity: field.entity,
                }));
                const paint = () => {
                    popup.replaceChildren();
                    popup.hidden = !helper.suggestions.length;
                    helper.suggestions.forEach((item, index) => {
                        const button = element('button', '', item.label + ' · ' + item.source);
                        button.type = 'button';
                        button.classList.toggle('is-active', index === helper.suggestionIndex);
                        button.addEventListener('pointerdown', (event) => {
                            event.preventDefault();
                            helper.chooseSuggestion(item);
                            paint();
                        });
                        popup.append(button);
                    });
                };
                input.addEventListener('focus', () => {
                    helper.sources = this.expressionSources();
                });
                input.addEventListener('input', (event) => {
                    helper.suggest(event);
                    paint();
                });
                input.addEventListener('keydown', (event) => {
                    helper.suggestionKey(event);
                    paint();
                });
                input.addEventListener('blur', () => {
                    popup.hidden = true;
                });
                wrapper.append(popup);
            },
            insertExpression(expression) {
                const target = this.lastFocusedField;
                if (!target?.input?.isConnected || !this.currentNode) {
                    this.copyValue(expression);
                    return;
                }
                const editor = target.path === 'javascript_code' ? this.codeEditorView?.() : null;
                if (editor) {
                    const { from, to } = editor.state.selection.main;
                    editor.dispatch({
                        changes: { from, to, insert: expression },
                        selection: { anchor: from + expression.length },
                    });
                    editor.focus();
                    this.expressionOpen = false;
                    return;
                }
                const input = target.input,
                    start = input.selectionStart ?? input.value.length,
                    end = input.selectionEnd ?? start;
                input.setRangeText(expression, start, end, 'end');
                this.machine.checkpoint();
                setPath(this.currentNode.config, target.path, input.value);
                input.focus();
                this.expressionOpen = false;
            },
            renderExpressions() {
                const host = this.$refs.expressions;
                if (!host) return;
                host.replaceChildren();
                const add = (label, expression) => {
                    const button = element('button', 'workflow-browser-expression-row', label);
                    button.type = 'button';
                    button.addEventListener('click', () => this.insertExpression(expression));
                    host.append(button);
                };
                add('Вход текущей ноды', '{{ $json }}');
                const vars = this.doc.definition.actions
                    .filter((node) => node.type === 'workflow_set_variable')
                    .flatMap((node) => node.config.assignments || []);
                vars.forEach((row) => {
                    if (/^[A-Za-z_][A-Za-z0-9_]*$/.test(row.name || ''))
                        add(row.name, '{{ $vars.' + row.name + ' }}');
                });
                this.expressionSources().forEach((source) =>
                    source.fields.forEach((field) => add(source.name + field.path, field.expression)),
                );
            },
            renderJson(host, value) {
                if (value === undefined) {
                    host.append(element('p', 'workflow-browser-empty', 'Результат не сохранён.'));
                    return;
                }
                const tree = element('div', 'workflow-browser-json');
                host.append(tree);
                const visit = (parent, value, label = '', depth = 0) => {
                    if (value && typeof value === 'object') {
                        const entries = Object.entries(value),
                            array = Array.isArray(value),
                            details = element('details'),
                            summary = element(
                                'summary',
                                '',
                                label +
                                    (array ? '[' : '{') +
                                    (entries.length ? ' ' + entries.length + ' ' : '') +
                                    (array ? ']' : '}'),
                            );
                        if (depth === 0) details.open = true;
                        let rendered = false;
                        const render = () => {
                            if (rendered) return;
                            rendered = true;
                            let count = 0;
                            const body = element('div', 'workflow-browser-json-children');
                            details.append(body);
                            const more = element('button', 'workflow-browser-button', 'Ещё значения');
                            more.type = 'button';
                            const batch = () => {
                                more.remove();
                                entries
                                    .slice(count, count + 100)
                                    .forEach(([key, child]) =>
                                        visit(
                                            body,
                                            child,
                                            array ? '' : JSON.stringify(key) + ': ',
                                            depth + 1,
                                        ),
                                    );
                                count += 100;
                                if (count < entries.length) body.append(more);
                            };
                            more.addEventListener('click', batch);
                            batch();
                        };
                        details.addEventListener('toggle', () => {
                            if (details.open) render();
                        });
                        details.append(summary);
                        parent.append(details);
                        if (details.open) render();
                    } else {
                        const row = element(
                            'button',
                            'workflow-browser-json-value',
                            label + (JSON.stringify(value) ?? 'null'),
                        );
                        row.type = 'button';
                        row.dataset.type = typeof value;
                        row.title = 'Скопировать значение';
                        row.setAttribute('aria-label', 'Скопировать ' + row.textContent);
                        row.addEventListener('click', () => this.copyValue(value));
                        parent.append(row);
                    }
                };
                visit(tree, value);
            },
        },
        {
            editorWire: {
                get() {
                    return this.clientWire || this.$wire;
                },
                configurable: true,
            },
        },
    );
}
