<x-filament-panels::page class="workflow-list-page">
    @include('filament.workflow-builder.workflow-overview-nav', ['active' => 'workflows', 'folder' => $this->workflowGroupFilter, 'launch' => $this->workflowLaunchFilter])
    @if((bool) auth()->user()?->is_root)
        {{ $this->table }}
    @else
    <div class="workflow-overview">
        @include('filament.workflow-builder.workflow-sidebar')
        <section class="workflow-overview__content">
            @if($this->workflowGroupFilter !== null || $this->workflowLaunchFilter !== null)
            <header class="workflow-overview__heading">
                <h2>{{ $this->workflowLaunchFilter !== null ? $this->selectedLaunchTypeLabel() : ($this->workflowGroupFilter === '__without_group__' ? 'Без папки' : $this->workflowGroupFilter) }}</h2>
                @if(filled($this->workflowGroupFilter) && $this->workflowGroupFilter !== '__without_group__')
                    <div>{{ $this->renameFolderAction }} {{ $this->deleteFolderAction }}</div>
                @endif
            </header>
            @endif
            {{ $this->table }}
        </section>
    </div>
    @endif
</x-filament-panels::page>
