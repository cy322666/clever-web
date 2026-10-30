<x-filament-panels::page class="workflow-list-page workflow-account-history-page">
    <div class="workflow-history-page">
        @include('filament.workflow-builder.workflow-overview-nav', ['active' => 'history', 'folder' => $this->workflowGroupFilter, 'launch' => $this->workflowLaunchFilter])

        <div @class(['workflow-overview workflow-account-history-overview', 'is-root' => (bool) auth()->user()?->is_root])>
            @unless((bool) auth()->user()?->is_root)
                @include('filament.workflow-builder.workflow-sidebar')
            @endunless
            <main class="workflow-account-history-list" aria-label="Запуски всех сценариев клиента">
                {{ $this->table }}
            </main>
        </div>
    </div>
</x-filament-panels::page>
