<x-filament-panels::page class="workflow-list-page">
    @include('filament.workflow-builder.workflow-overview-nav', ['active' => 'workflows'])
    @if((bool) auth()->user()?->is_root)
        <div class="workflow-overview workflow-overview--admin">
            <section class="workflow-overview__content">
                {{ $this->table }}
            </section>
        </div>
    @else
    @php($overview = $this->folderOverview())
    @php($startTypes = $this->startTypeOverview())
    @php($currentHeading = $this->currentListHeading())
    <div class="workflow-overview">
        <aside class="workflow-folders" aria-label="Папки и типы запуска сценариев">
            <div class="workflow-folders__heading">
                <span>Папки</span>
                {{ $this->createFolderAction }}
            </div>
            <nav>
                <button type="button" wire:click="selectFolder(null)" @class(['workflow-folders__item', 'is-active' => $this->workflowGroupFilter === null && $this->workflowStartTypeFilter === null]) @if($this->workflowGroupFilter === null && $this->workflowStartTypeFilter === null) aria-current="page" @endif>
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
            @if($startTypes !== [])
                <div class="workflow-folders__heading workflow-folders__heading--starts">
                    <span>Тип запуска</span>
                </div>
                <nav>
                    @foreach($startTypes as $startType)
                        <button type="button" wire:key="workflow-start-{{ md5($startType['type']) }}" wire:click="selectStartType(@js($startType['type']))" @class(['workflow-folders__item', 'is-active' => $this->workflowStartTypeFilter === $startType['type']]) @if($this->workflowStartTypeFilter === $startType['type']) aria-current="page" @endif title="{{ $startType['label'] }}">
                            <x-filament::icon :icon="$startType['icon']" class="h-4 w-4"/>
                            <span>{{ $startType['label'] }}</span><small>{{ $startType['count'] }}</small>
                        </button>
                    @endforeach
                </nav>
            @endif
        </aside>
        <section class="workflow-overview__content">
            @if($currentHeading !== null)
            <header class="workflow-overview__heading">
                <h2>{{ $currentHeading }}</h2>
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
