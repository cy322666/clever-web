<x-filament-panels::page>
    @include('filament.workflow-builder.workflow-admin-context')
    @php($browserBoot = $this->browserBoot())
    <div wire:ignore x-data="workflowBrowserEditor(JSON.parse($el.querySelector('[data-browser-boot]').textContent))">
        <script type="application/json" data-browser-boot>{!! json_encode($browserBoot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) !!}</script>
        <p x-show="!ready" role="status" x-text="loadError || 'Открываю редактор…'">Открываю редактор…</p>
        <template x-if="ready">
            <div x-data="editor" x-ref="browserEditor" x-init="$nextTick(() => mountEditor())"
                 class="workflow-editor-page workflow-workbench workflow-workbench--page workflow-browser"
                 x-on:resize.window="syncBrowserHeight()" x-on:keydown.window="browserShortcut($event)"
                 x-on:workflow-node-library-open.window="openLibrary($event.detail.mode)"
                 x-on:workflow-browser-credentials-updated.window="references.telegram=$event.detail.options; renderInspector()"
                 x-on:pointermove.window="moveCanvas($event)" x-on:pointerup.window="stopCanvasInteraction($event)"
                 x-on:pointercancel.window="stopCanvasInteraction($event)" x-on:blur.window="stopCanvasPan(true)"
                 x-on:keydown.escape.window="stopCanvasPan(true); stopAreaDrag(true); cancelConnection(); libraryOpen=false; expressionOpen=false; referencesOpen=false;">
              <div class="workflow-workbench__shell">
                <header class="workflow-workbench__toolbar">
                    <div class="workflow-workbench__toolbar-layout">
                        <div class="workflow-workbench__identity">
                            <a :href="listUrl" class="workflow-workbench__quick-action" aria-label="К списку сценариев"><x-filament::icon icon="heroicon-o-arrow-left" class="h-5 w-5"/></a>
                            <input class="workflow-browser-title" aria-label="Название сценария" maxlength="255" :value="doc.name" x-on:input="setDocument('name',$el.value)" />
                            <select class="workflow-browser-folder" aria-label="Папка" :value="doc.group_name || ''" x-on:change="setDocument('group_name',$el.value || null)">
                                <option value="">Без папки</option>
                                <template x-for="(name,id) in folders" :key="id"><option :value="id" x-text="name"></option></template>
                            </select>
                        </div>
                        <div class="workflow-workbench__quick-actions">
                            <button type="button" class="workflow-workbench__quick-action" x-on:click="referencesOpen=!referencesOpen" title="Справочники" aria-label="Справочники"><x-filament::icon icon="heroicon-o-book-open" class="h-5 w-5"/></button>
                            <button type="button" class="workflow-workbench__quick-action workflow-workbench__quick-action--labeled" :aria-pressed="doc.is_active" :disabled="saving" x-on:click="saveDocument(!doc.is_active)"><x-filament::icon icon="heroicon-o-power" class="h-4 w-4"/><span x-text="doc.is_active ? 'Выключить' : 'Включить'"></span></button>
                            <button type="button" class="workflow-workbench__quick-action" title="Подключения" aria-label="Подключения" x-on:click="$wire.mountAction('workflowCredentials')"><x-filament::icon icon="heroicon-o-key" class="h-5 w-5"/></button>
                            <button type="button" class="workflow-workbench__quick-action" title="Отладка" aria-label="Отладка" x-on:click="debugOpen=!debugOpen"><x-filament::icon icon="heroicon-o-beaker" class="h-5 w-5"/></button>
                            <a x-show="historyUrl" :href="historyUrl" class="workflow-workbench__quick-action" title="История" aria-label="История"><x-filament::icon icon="heroicon-o-clock" class="h-5 w-5"/></a>
                            <button type="button" class="workflow-workbench__quick-action workflow-workbench__quick-action--primary workflow-workbench__quick-action--labeled" :disabled="saving" :aria-busy="saving" :data-unsaved="hasUnsavedChanges" x-on:click="saveDocument()">
                                <span class="workflow-run-spinner" x-show="saving"></span><span x-text="saving ? 'Сохраняю…' : hasUnsavedChanges ? 'Сохранить' : 'Сохранено'"></span>
                            </button>
                            <details class="workflow-workbench__more" x-on:click.outside="$el.open=false">
                                <summary class="workflow-workbench__quick-action" aria-label="Действия"><x-filament::icon icon="heroicon-o-ellipsis-horizontal" class="h-5 w-5"/></summary>
                                <div class="workflow-workbench__menu" x-on:click="$el.closest('details').open=false">
                                    <button type="button" x-on:click="exportDocument()">Экспорт JSON</button>
                                    <button type="button" x-on:click="$refs.importFile.click()">Импорт JSON</button>
                                    <button type="button" x-on:click="resetNodeLayout()">Выровнять блоки</button>
                                    <button type="button" x-on:click="settingsOpen=!settingsOpen">Настройки сценария</button>
                                    <button type="button" x-show="doc.id" :disabled="saving" x-on:click="duplicateDocument()">Дублировать</button>
                                    <button type="button" x-show="doc.id" :disabled="saving" x-on:click="deleteDocument()" class="workflow-workbench__menu-danger">Удалить сценарий</button>
                                </div>
                            </details>
                            <input x-ref="importFile" type="file" accept="application/json,.json" hidden x-on:change="importDocument($el.files[0]); $el.value=''" />
                        </div>
                    </div>
                </header>
                <div class="workflow-browser-error" role="alert" x-show="error" x-cloak><span x-text="error"></span><button type="button" aria-label="Закрыть ошибку" x-on:click="error=''">×</button></div>
                <div class="workflow-browser-settings" x-show="settingsOpen" x-cloak>
                    <label>При ошибке<select class="workflow-browser-input" :value="doc.failure_strategy" x-on:change="setDocument('failure_strategy',$el.value)"><option value="stop">Остановить сценарий</option><option value="continue">Продолжить</option><option value="telegram_report">Отчёт в Telegram</option></select></label>
                    <label>Теги<input class="workflow-browser-input" :value="(doc.definition.tags || []).join(', ')" x-on:change="setDocument('definition.tags',$el.value.split(',').map(tag=>tag.trim()).filter(Boolean))" /></label>
                </div>
                <div class="workflow-workbench__main">
                    <section class="workflow-node-editor">
                        <div x-ref="viewport" class="workflow-node-editor__viewport has-free-node-layout"
                             :class="{'is-node-dragging':draggingNodeId !== null,'is-selecting':selectionBox !== null}"
                             x-on:pointerdown="startCanvasInteraction($event)" x-on:lostpointercapture="stopCanvasPan(true,$event.pointerId)"
                             x-on:wheel="onCanvasWheel($event)" x-on:click.capture="suppressNodeClick($event)"
                             x-on:dblclick="openCanvasPalette($event)" x-on:contextmenu="openCanvasPalette($event)">
                            @include('filament.workflow-builder.workflow-canvas-grid')
                            <div class="workflow-selection-box" x-show="selectionBox" :style="selectionStyle()"></div>
                            <div class="workflow-canvas-note" x-on:pointerdown.stop>
                                <button type="button" x-on:click="descriptionOpen=!descriptionOpen" :aria-expanded="descriptionOpen" aria-label="Описание сценария"><x-filament::icon icon="heroicon-o-document-text" class="h-4 w-4"/></button>
                                <textarea x-show="descriptionOpen" :value="doc.definition.description || ''" x-on:input="setDocument('definition.description',$el.value)" maxlength="10000" aria-label="Описание сценария"></textarea>
                            </div>
                            <div class="workflow-canvas-controls" x-on:pointerdown.stop>
                                <button type="button" class="workflow-canvas-fit" x-on:click="pendingDropPosition=null; pendingInsertion=null; pendingAdapter.insertConnection=null; openLibrary()" aria-label="Добавить ноду" title="Добавить ноду"><x-filament::icon icon="heroicon-o-plus" class="h-4 w-4"/></button>
                                <button type="button" class="workflow-canvas-fit" x-on:click="addCanvasArea()" aria-label="Создать область" title="Создать область"><x-filament::icon icon="heroicon-o-rectangle-group" class="h-4 w-4"/></button>
                                <button type="button" class="workflow-canvas-fit" x-on:click="fitView()" aria-label="Показать весь сценарий" title="Показать весь сценарий"><x-filament::icon icon="heroicon-o-arrows-pointing-in" class="h-4 w-4"/></button>
                            </div>
                            <button type="button" class="workflow-canvas-run" x-on:pointerdown.stop x-on:click="debugOpen=true" aria-label="Открыть отладку"><x-filament::icon icon="heroicon-s-play" class="h-4 w-4"/> Запустить</button>
                            <div class="workflow-connection-hint" x-show="connecting || connectionError" x-on:pointerdown.stop><span x-text="connectionError || 'Выберите другую ноду'"></span><button type="button" x-on:click="cancelConnection()">Закрыть</button></div>
                            <div x-ref="stage" :style="stageStyle()" class="workflow-node-editor__stage">
                                @include('filament.workflow-builder.workflow-canvas-areas')
                                <svg x-ref="edgeLayer" class="workflow-node-edge-layer" aria-hidden="true"></svg>
                                <main id="workflow-canvas" class="workflow-browser-canvas">
                                    <template x-for="node in visibleNodes" :key="node.id">
                                        <div class="workflow-node-shell" :data-workflow-node-id="node.id" :style="nodeStyle(node.id)">
                                            <div data-workflow-node-card class="workflow-card workflow-node-card workflow-node-card--compact" tabindex="0" role="button"
                                                 :data-node-family="metadata(node).appearance.family" :data-node-tone="metadata(node).appearance.tone"
                                                 :data-workflow-node-disabled="node.disabled?'true':'false'" :data-node-running="runningId===node.id" :data-connection-target="connectionTargetId===node.id"
                                                 :class="{'workflow-node-card--disabled':node.disabled,'workflow-node-card--selected':selectedNodeIds.includes(node.id)}"
                                                 :aria-label="'Настроить: '+nodeName(node)" x-on:keydown.enter.self.prevent="openNode(node.id)"
                                                 x-on:pointerup.capture="if (connecting && !node.id.startsWith('trigger') && !$event.target.closest('.workflow-node-port')) finishConnection(node.id,$event)"
                                                 x-on:click="if(connecting && !node.id.startsWith('trigger')) completeConnection(node.id); else openNode(node.id)">
                                                <template x-if="!node.id.startsWith('trigger')"><button type="button" class="workflow-node-port workflow-node-port--input" x-on:pointerdown.stop="startIncomingConnection(node.id,$event)" x-on:pointerup.stop="if(connecting?.replaceTarget!==node.id) finishConnection(node.id,$event)" x-on:click.stop="if(connecting?.replaceTarget!==node.id) completeConnection(node.id)" aria-label="Вход"></button></template>
                                                <template x-for="port in nodePorts(node)" :key="port"><button type="button" class="workflow-node-port workflow-node-port--output" :class="'workflow-node-port--output-'+port" x-on:pointerdown.stop="startConnection(node.id,port,$event)" x-on:click.stop :aria-label="'Выход '+portLabel(port)"><span class="workflow-node-port__label" x-text="portLabel(port)"></span></button></template>
                                                <span class="workflow-node-card__icon" x-html="metadata(node).icon"></span>
                                                <span class="workflow-node-result" data-workflow-node-result hidden></span>
                                                <span data-workflow-node-status hidden></span>
                                                <div class="workflow-node-card__caption"><h4 class="workflow-node-card__title" x-text="nodeName(node)"></h4><p class="workflow-node-card__subtitle" x-text="subtitle(node)"></p></div>
                                                <div class="workflow-node-card__tools" x-on:pointerdown.stop x-on:click.stop>
                                                    <button type="button" x-show="!node.id.startsWith('trigger')" :disabled="running || !!node.disabled" x-on:click="runDebug('node',node.id)" aria-label="Выполнить ноду" title="Выполнить только эту ноду"><x-filament::icon icon="heroicon-o-play" class="h-4 w-4"/></button>
                                                    <button type="button" x-on:click="removeNode(node.id)" aria-label="Удалить ноду"><x-filament::icon icon="heroicon-o-trash" class="h-4 w-4"/></button>
                                                    <button type="button" x-show="!node.id.startsWith('trigger')" x-on:click="toggleNode(node.id)" :aria-pressed="!!node.disabled" aria-label="Выключить ноду"><x-filament::icon icon="heroicon-o-power" class="h-4 w-4"/></button>
                                                    <button type="button" x-on:click="duplicateNode(node.id)" aria-label="Скопировать ноду"><x-filament::icon icon="heroicon-o-document-duplicate" class="h-4 w-4"/></button>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </main>
                                <div x-ref="edgeControls" class="workflow-node-edge-controls">
                                    <template x-for="edge in canvasEdges([],startIds())" :key="edgeRouteKey(edge)">
                                        <div style="display:contents">
                                            <button type="button" class="workflow-node-edge-add" :data-workflow-edge-source="edge.sourceId" :data-workflow-edge-port="edge.sourcePort" :data-workflow-edge-target="edge.targetId" :data-workflow-loop-return="edge.loopReturn?'true':'false'" :data-workflow-loop-feedback="edge.loopFeedback?'true':'false'"
                                                    x-on:pointerdown.stop="startEdgeControlDrag(edge.sourceId,edge.sourcePort,edge.targetId,$event)" x-on:click.stop="openEdgePalette(edge.sourceId,edge.sourcePort,edge.targetId)" x-on:pointerenter="if(edge.targetId) showEdgeControls($el)" x-on:pointerleave="if(edge.targetId) hideEdgeControls($el)" aria-label="Добавить действие"><x-filament::icon icon="heroicon-o-plus" class="h-3.5 w-3.5"/></button>
                                            <button type="button" class="workflow-node-edge-add" x-show="edge.targetId" :data-workflow-edge-delete="edge.targetId?'':null" x-on:pointerdown.stop x-on:click.stop="selectedEdge=edge; removeSelectedEdge()" x-on:pointerenter="showEdgeControls($el.previousElementSibling)" x-on:pointerleave="hideEdgeControls($el.previousElementSibling)" aria-label="Удалить связь"><x-filament::icon icon="heroicon-o-trash" class="h-3.5 w-3.5"/></button>
                                        </div>
                                    </template>
                                </div>
                            </div>
                            <button type="button" class="workflow-browser-empty-start workflow-browser-button" x-show="!visibleNodes.length" x-on:pointerdown.stop x-on:click="openLibrary('trigger')">Выбрать запуск</button>
                        </div>
                    </section>
                </div>
                <aside class="workflow-browser-inspector" x-show="selectedId" x-cloak :aria-label="'Настройки '+nodeName(currentNode)" x-on:keydown.escape.stop="closeInspector()">
                    <header><input aria-label="Название ноды" :value="currentNode?.name || ''" x-on:change="renameCurrent($el.value)" maxlength="100" /><button type="button" x-on:click="closeInspector()" aria-label="Закрыть настройки">×</button></header>
                    <nav role="tablist" aria-label="Данные ноды">
                        <template x-for="tab in [{id:'settings',label:'Настройки'},{id:'input',label:'Вход'},{id:'output',label:'Выход'}]" :key="tab.id"><button type="button" role="tab" :aria-selected="inspectorTab===tab.id" x-on:click="switchTab(tab.id)" x-text="tab.label"></button></template>
                        <button type="button" x-show="currentNode?.type.startsWith('amocrm_')" x-on:click="refreshReferences()" :disabled="running" title="Обновить справочники" aria-label="Обновить справочники"><x-filament::icon icon="heroicon-o-arrow-path" class="h-4 w-4"/></button>
                        <button type="button" x-show="selectedId?.startsWith('action:')" :disabled="running || !!currentNode?.disabled" x-on:click="runDebug('node',selectedId)" class="workflow-browser-run"><span x-show="running" class="workflow-run-spinner"></span><span x-text="running ? 'Выполняю…' : 'Запустить'"></span></button>
                    </nav>
                    <div x-ref="nodeFields" class="workflow-browser-fields"></div>
                    <div class="workflow-browser-expressions" x-show="expressionOpen" x-cloak><header>Переменные<button type="button" aria-label="Закрыть переменные" x-on:click="expressionOpen=false">×</button></header><div x-ref="expressions"></div></div>
                </aside>
                <section class="workflow-browser-debug" x-show="debugOpen" x-cloak aria-label="Отладка">
                    <header><strong>Отладка</strong><label><input type="checkbox" x-model="debugReal" x-on:change="debugSession=null" /> Реальные действия</label><button type="button" x-on:click="debugOpen=false; debugPlaying=false" aria-label="Закрыть отладку">×</button></header>
                    <div class="workflow-browser-debug__body"><div><label>Запуск<select class="workflow-browser-input" x-model="debugStartNodeId" x-on:change="debugSession=null"><template x-for="start in machine.starts()" :key="start.id"><option :value="start.id" x-text="start.name"></option></template></select></label><label>Входные данные<textarea class="workflow-browser-input workflow-browser-code" x-model="debugInput" rows="5"></textarea></label></div><div>
                        <button type="button" class="workflow-browser-button" x-on:click="loadDebugInput()" :disabled="running">Взять последний вход</button>
                        <button type="button" class="workflow-browser-button" x-on:click="debugSession=null; runDebug('start')" :disabled="running">Сначала</button>
                        <button type="button" class="workflow-browser-button" x-on:click="runDebug('step')" :disabled="running || debugState.status!=='ready'">Следующий шаг</button>
                        <button type="button" class="workflow-browser-button" x-on:click="playDebug()" x-text="debugPlaying?'Остановить':'Выполнить'" :disabled="running && !debugPlaying"></button>
                        <p role="status" x-text="running ? 'Выполняю…' : ({ready:'Пауза',completed:'Завершено',failed:'Ошибка'}[debugState.status] || '')"></p>
                        <template x-for="(result,index) in debugState.results" :key="index"><button type="button" class="workflow-browser-debug-result" x-on:click="openNode('action:'+result.id); switchTab('output')"><span x-text="result.name || result.type"></span><small x-text="result.duration_ms+' мс'"></small></button></template>
                    </div></div>
                </section>
                <section class="workflow-browser-library" x-show="libraryOpen" x-cloak aria-label="Добавить ноду" x-on:keydown.escape.stop="libraryOpen=false">
                    <header><strong>Добавить ноду</strong><button type="button" x-on:click="libraryOpen=false; pendingAdapter.insertConnection=null" aria-label="Закрыть список нод">×</button></header>
                    <input x-ref="librarySearch" x-model="librarySearch" class="workflow-browser-input" placeholder="Поиск нод…" aria-label="Поиск нод" />
                    <nav><button type="button" :aria-pressed="libraryMode==='all'" x-on:click="libraryMode='all'">Все</button><button type="button" :aria-pressed="libraryMode==='trigger'" x-on:click="libraryMode='trigger'">Запуски</button><button type="button" :aria-pressed="libraryMode==='actions'" x-on:click="libraryMode='actions'">Действия</button></nav>
                    <div class="workflow-browser-library__items"><template x-for="item in libraryItems()" :key="(item.trigger?'trigger:':'action:')+(item.operation || item.type)"><button type="button" x-on:click="insertNode(item)"><span x-html="item.icon"></span><span><strong x-text="item.name"></strong><small x-text="item.category"></small></span></button></template></div>
                </section>
                <section class="workflow-browser-library" x-show="referencesOpen" x-cloak aria-label="Справочники">
                    <header><strong>Справочники</strong><button type="button" x-on:click="referencesOpen=false" aria-label="Закрыть справочники">×</button></header>
                    <select class="workflow-browser-input" x-model="referenceEntity" aria-label="Тип записи справочника"><option value="leads">Поля сделки</option><option value="contacts">Поля контакта</option><option value="companies">Поля компании</option><option value="customers">Поля покупателя</option></select>
                    <input class="workflow-browser-input" x-model="referenceSearch" aria-label="Поиск поля" placeholder="Название или ID поля" />
                    <div class="workflow-browser-library__items"><template x-for="field in referenceFields()" :key="field.id"><button type="button" x-on:click="copyValue(referenceExpression(field))"><span><strong x-text="field.name"></strong><small x-text="'ID '+field.id"></small></span></button></template></div>
                </section>
                <div class="workflow-browser-toast" role="status" aria-live="polite" x-show="notice" x-text="notice" x-cloak></div>
              </div>
            </div>
        </template>
    </div>
    <x-filament-actions::modals />
</x-filament-panels::page>
