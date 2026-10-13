@props(['appearance'])
{{-- Original library SVGs, composed as an entity silhouette and its action cue. --}}
<span {{ $attributes->class(['workflow-node-glyph', 'workflow-node-glyph--action' => $appearance['modifier'] !== null, 'workflow-node-glyph--assignment' => $appearance['modifier'] === 'equals']) }} aria-hidden="true">
    @if($appearance['modifier'] !== null)
        <x-filament::icon :icon="'wficon-'.$appearance['glyph'].($appearance['modifier'] === 'equals' ? '-bold' : '-fill')" class="workflow-node-glyph__entity"/>
        <x-filament::icon :icon="'wficon-'.$appearance['modifier'].'-bold'" class="workflow-node-glyph__action"/>
    @else
        <x-filament::icon :icon="'wficon-'.$appearance['glyph'].($appearance['glyph'] === 'telegram-logo' ? '-fill' : '-duotone')" class="workflow-node-glyph__main"/>
    @endif
</span>
