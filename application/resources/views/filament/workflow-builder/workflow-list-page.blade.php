<x-filament-panels::page class="workflow-list-page">
    @include('filament.workflow-builder.workflow-overview-nav', ['active' => 'workflows'])
    @if((bool) auth()->user()?->is_root)
        {{ $this->table }}
    @else
    @php($overview = $this->folderOverview())
    @php($launchTypes = $this->launchTypeOverview())
    <div class="workflow-overview">
        <aside class="workflow-folders" aria-label="Группы сценариев">
            <div class="workflow-folders__section">
                <div class="workflow-folders__heading">
                    <span>Папки</span>
                    {{ $this->createFolderAction }}
                </div>
                <nav aria-label="Папки">
                    <button type="button" wire:click="selectFolder(null)" @class(['workflow-folders__item', 'is-active' => $this->workflowGroupFilter === null && $this->workflowLaunchFilter === null]) @if($this->workflowGroupFilter === null && $this->workflowLaunchFilter === null) aria-current="page" @endif>
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
            </div>
            @if($launchTypes !== [])
                <div class="workflow-folders__section workflow-folders__section--launches">
                    <div class="workflow-folders__heading"><span>Тип запуска</span></div>
                    <nav aria-label="Тип запуска">
                        @foreach($launchTypes as $launchType)
                            <button type="button" wire:key="workflow-launch-{{ $launchType['key'] }}" wire:click="selectLaunchType(@js($launchType['key']))" @class(['workflow-folders__item', 'is-active' => $this->workflowLaunchFilter === $launchType['key']]) @if($this->workflowLaunchFilter === $launchType['key']) aria-current="page" @endif>
                                <x-workflow-icon :icon="$launchType['icon']" :amo="str_starts_with($launchType['icon'], 'amocrm-')" class="h-4 w-4"/>
                                <span>{{ $launchType['label'] }}</span><small>{{ $launchType['count'] }}</small>
                            </button>
                        @endforeach
                    </nav>
                </div>
            @endif
        </aside>
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
