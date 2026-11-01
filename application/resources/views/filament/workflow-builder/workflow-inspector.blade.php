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
    x-on:resize.window="positionInspector()" x-bind:hidden="!opened" hidden
    x-bind:style="position" x-bind:data-debug-embedded="embedded ? '' : null" x-bind:aria-busy="loading" aria-label="Настройки ноды">
    @php($inspectorBootstrap = $this->workflowInspectorBootstrap())
    <script type="application/json" data-local-inspector-boot>{!! json_encode($inspectorBootstrap, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR) !!}</script>
    <header class="workflow-inspector__header">
        <strong x-text="title || 'Нода'"></strong>
        <x-filament::icon-button tag="a" :href="\App\Services\Workflows\WorkflowDocumentationLinks::BASE_URL.'/blocks'" target="_blank" rel="noopener noreferrer" color="gray" size="sm" icon="heroicon-o-question-mark-circle" label="Справка о ноде" title="Справка о ноде"
            x-data="{ pages: {{ \Illuminate\Support\Js::from(['types' => \App\Services\Workflows\WorkflowDocumentationLinks::typePages(), 'reads' => \App\Services\Workflows\WorkflowDocumentationLinks::readPages()]) }} }"
            x-show="typeof local !== 'undefined' && local && local.id"
            x-bind:href="(() => {
                const inspector = typeof local !== 'undefined' ? local : null;
                if (!inspector?.id) return {{ \Illuminate\Support\Js::from(\App\Services\Workflows\WorkflowDocumentationLinks::BASE_URL.'/blocks') }};
                void inspector.revision;
                const type = inspector.type;
                const operation = inspector.config?.operation;
                const page = type === 'amocrm_read'
                    ? (Object.hasOwn(pages.reads, operation) ? pages.reads[operation] : 'query')
                    : (Object.hasOwn(pages.types, type) ? pages.types[type] : 'blocks');
                return {{ \Illuminate\Support\Js::from(\App\Services\Workflows\WorkflowDocumentationLinks::BASE_URL.'/') }} + page;
            })()" />
        <button type="button" x-on:click="hideInspector()" aria-label="Закрыть настройки" title="Закрыть настройки"><x-filament::icon icon="heroicon-m-x-mark" class="h-4 w-4"/></button>
    </header>
    <nav class="workflow-inspector__tabs" role="tablist" aria-label="Данные ноды">
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
