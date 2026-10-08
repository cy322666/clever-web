<div class="workflow-node-navigation" style="display: inline-flex; align-items: center; gap: 12px; max-width: 100%">
    @foreach(['previous' => ['nodes' => $previous, 'label' => 'Предыдущая нода', 'icon' => 'heroicon-m-chevron-left'], 'next' => ['nodes' => $next, 'label' => 'Следующая нода', 'icon' => 'heroicon-m-chevron-right']] as $direction => $control)
        @if(count($control['nodes']) > 1)
            <x-filament::dropdown placement="bottom-start" :wire:key="'node-nav-'.$direction">
                <x-slot name="trigger">
                    <x-filament::icon-button :icon="$control['icon']" :label="$control['label']" :title="$control['label']" color="gray" size="sm" wire:loading.attr="disabled" wire:target="navigateWorkflowNode,runEditingWorkflowNode" />
                </x-slot>
                <x-filament::dropdown.list>
                    @foreach($control['nodes'] as $target)
                        <x-filament::dropdown.list.item :wire:click="'navigateWorkflowNode('.\Illuminate\Support\Js::from($target['id'])->toHtml().')'" wire:loading.attr="disabled" wire:target="navigateWorkflowNode,runEditingWorkflowNode">
                            {{ $target['label'] }}
                        </x-filament::dropdown.list.item>
                    @endforeach
                </x-filament::dropdown.list>
            </x-filament::dropdown>
        @else
            <x-filament::icon-button :icon="$control['icon']" :label="$control['label']" :title="isset($control['nodes'][0]) ? $control['nodes'][0]['label'] : $control['label']" color="gray" size="sm" :disabled="empty($control['nodes'])" :wire:click="'navigateWorkflowNode('.\Illuminate\Support\Js::from($control['nodes'][0]['id'] ?? '')->toHtml().')'" wire:loading.attr="disabled" wire:target="navigateWorkflowNode,runEditingWorkflowNode" />
        @endif
    @endforeach
    <span style="overflow-wrap: anywhere">{{ $name }}</span>
</div>
