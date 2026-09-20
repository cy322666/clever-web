<x-filament-panels::page class="workflow-list-page">
    @include('filament.workflow-builder.workflow-overview-nav', ['active' => 'workflows'])
    @if((bool) auth()->user()?->is_root)
        {{ $this->table }}
    @else
    @php($overview = $this->folderOverview())
    <div class="workflow-overview">
        <aside class="workflow-folders" aria-label="Папки сценариев">
            <div class="workflow-folders__heading">
                <span>Папки</span>
                {{ $this->createFolderAction }}
            </div>
            <nav>
                <button type="button" wire:click="selectFolder(null)" @class(['workflow-folders__item', 'is-active' => $this->workflowGroupFilter === null]) @if($this->workflowGroupFilter === null) aria-current="page" @endif>
                    <x-filament::icon icon="heroicon-o-squares-2x2" class="h-4 w-4"/>
                    <span>Все сценарии</span><small>{{ $overview['total'] }}</small>
                </button>
                <button type="button" wire:click="selectFolder('__without_group__')" @class(['workflow-folders__item', 'is-active' => $this->workflowGroupFilter === '__without_group__']) @if($this->workflowGroupFilter === '__without_group__') aria-current="page" @endif>
                    <x-filament::icon icon="heroicon-o-document" class="h-4 w-4"/>
                    <span>Без папки</span><small>{{ $overview['root'] }}</small>
                </button>
                @foreach($overview['folders'] as $folder)
                    <button type="button" wire:key="workflow-folder-{{ md5($folder['name']) }}" wire:click="selectFolder(@js($folder['name']))" @class(['workflow-folders__item', 'is-active' => $this->workflowGroupFilter === $folder['name']]) @if($this->workflowGroupFilter === $folder['name']) aria-current="page" @endif title="{{ $folder['name'] }}">
                        <x-filament::icon icon="heroicon-o-folder" class="h-4 w-4"/>
                        <span>{{ $folder['name'] }}</span><small>{{ $folder['count'] }}</small>
                    </button>
                @endforeach
            </nav>
        </aside>
        <section class="workflow-overview__content">
            @if($this->workflowGroupFilter !== null)
            <header class="workflow-overview__heading">
                <h2>{{ $this->workflowGroupFilter === '__without_group__' ? 'Без папки' : $this->workflowGroupFilter }}</h2>
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
