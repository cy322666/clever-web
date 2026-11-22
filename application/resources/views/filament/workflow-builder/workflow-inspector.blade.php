<aside id="workflow-node-inspector-{{ $this->getId() }}" class="workflow-inspector workflow-node-settings" x-data="workflowInspector()"
    x-on:workflow-inspector-open.window="if ($event.detail.componentId === $wire.$id) openInspector($event.detail)"
    x-on:workflow-inspector-close.window="if ($event.detail.componentId === $wire.$id) hideInspector()"
    x-on:workflow-debug-settings.window="syncDebugSettings($event.detail)"
    x-on:workflow-local-inspector-validation.window="showLocalValidation($event.detail)"
    x-on:workflow-local-document-changed.window="if (typeof local !== 'undefined' && $event.detail.componentId === $wire.$id) local?.synchronize()"
    x-on:workflow-debug-inspection.window="if (typeof local !== 'undefined' && local && $event.detail.componentId === $wire.$id) { local.sourceCache=null; if (local.id) local.renderInput(); }"
    x-on:sync-action-modals.window="syncInspectorActions($event.detail)"
    x-on:pointerdown.window.capture="dismissInspector($event)"
    x-on:form-validation-error.window="if ($event.detail.livewireId === $wire.$id) showValidationErrors()"
    x-on:keydown.escape.stop="if (!$event.defaultPrevented) hideInspector()"
    x-on:resize.window="queueInspectorPosition()" x-bind:hidden="!opened" hidden
    x-bind:style="position" x-bind:data-placement="placement" x-bind:data-expanded="expanded && !embedded ? '' : null" x-bind:data-debug-embedded="embedded ? '' : null" x-bind:aria-busy="loading" aria-label="Настройки ноды" x-bind:aria-label="'Настройки: ' + (title || 'Нода')">
    @php($inspectorBootstrap = $this->workflowInspectorBootstrap())
    <script type="application/json" data-local-inspector-boot>{!! json_encode($inspectorBootstrap, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR) !!}</script>
    <div class="workflow-settings-actions" role="toolbar" aria-label="Действия ноды" aria-orientation="horizontal" x-bind:style="actionPosition" x-show="!embedded">
        <div class="workflow-inspector__primary-actions" wire:ignore x-show="!loading" x-cloak>
            <div class="workflow-inspector__test-menu" x-data="{ menu: false }" x-show="testAvailable()"
                x-on:click.outside="menu = false" x-on:keydown.escape.prevent.stop="if (menu) { menu = false; $refs.testButton.focus() } else hideInspector()">
                <button type="button" class="workflow-inspector__action" x-ref="testButton" x-on:click="menu = !menu; if (menu) $nextTick(() => $refs.simulateButton.focus())" aria-controls="workflow-test-options-{{ $this->getId() }}"
                    aria-label="Тест" title="Тест" x-bind:aria-expanded="menu" x-bind:disabled="Boolean(runningTest) || workflowNodeIsRunning($wire)">
                    <x-filament::icon icon="heroicon-s-play" class="h-4 w-4"/>
                </button>
                <div id="workflow-test-options-{{ $this->getId() }}" x-show="menu" x-cloak aria-label="Режим теста">
                    <button type="button" x-ref="simulateButton" x-on:click="menu = false; $refs.testButton.focus(); runInspectorTest(false)"
                        title="Не меняет CRM; Read-ноды могут читать данные">Симуляция</button>
                    <button type="button" x-on:click="menu = false; $refs.testButton.focus(); runInspectorTest(true)">Реальный тест…</button>
                </div>
            </div>
            <button type="button" class="workflow-inspector__action workflow-inspector__apply" x-on:click="closeInspector()" aria-label="Применить" title="Применить">
                <x-filament::icon icon="heroicon-o-check" class="h-4 w-4"/>
            </button>
        </div>
    </div>
    <nav x-show="expanded || embedded" class="workflow-inspector__tabs" role="tablist" aria-label="Данные ноды">
        @foreach(['settings' => 'Настройки', 'input' => 'Вход', 'output' => 'Выход'] as $tab => $label)
            <button type="button" role="tab" x-on:click="tab = @js($tab)" x-bind:aria-selected="tab === @js($tab)" x-bind:tabindex="tab === @js($tab) ? 0 : -1" x-on:keydown.arrow-right.prevent="nextTab(1)" x-on:keydown.arrow-left.prevent="nextTab(-1)">{{ $label }}</button>
        @endforeach
    </nav>
    <div class="workflow-inspector__loading" role="status" x-show="loading"><span class="workflow-run-spinner" aria-hidden="true"></span> Загружаем настройки…</div>
    <p class="workflow-inspector__error" role="alert" x-show="error" x-text="error"></p>
    <div class="workflow-inspector__content" x-show="!loading">
        @include('filament.workflow-builder.workflow-local-inspector')
        <div x-show="typeof local === 'undefined' || !local">
        @foreach ($this->getMountedActions() as $action)
            @if ($this->isWorkflowInspectorAction($action))
                {{ $action->toModalHtmlable() }}
            @endif
        @endforeach
        </div>
    </div>
</aside>
