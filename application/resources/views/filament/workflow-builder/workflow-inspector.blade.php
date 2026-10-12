<aside class="workflow-inspector workflow-node-settings" x-data="workflowInspector()"
    x-on:workflow-inspector-open.window="if ($event.detail.componentId === $wire.$id) openInspector($event.detail)"
    x-on:workflow-inspector-close.window="if ($event.detail.componentId === $wire.$id) hideInspector()"
    x-on:sync-action-modals.window="if (!processing && $event.detail.id === $wire.$id && $event.detail.newActionNestingIndex === null) { opened = false; currentId = null; announce() }"
    x-on:pointerdown.window.capture="dismissInspector($event)"
    x-on:form-validation-error.window="if ($event.detail.livewireId === $wire.$id) showValidationErrors()"
    x-on:keydown.escape.stop="if (!$event.defaultPrevented) hideInspector()"
    x-on:resize.window="positionInspector()" x-bind:hidden="!opened" hidden
    x-bind:style="position" x-bind:aria-busy="loading" aria-label="Настройки ноды">
    <header class="workflow-inspector__header">
        <strong x-text="title || 'Нода'"></strong>
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
        @foreach ($this->getMountedActions() as $action)
            @if ($this->isWorkflowInspectorAction($action))
                {{ $action->toModalHtmlable() }}
            @endif
        @endforeach
    </div>
</aside>
