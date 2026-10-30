@props(['appearance'])
@php
    // Use the selected artwork itself, not a similarly named library substitute.
    $artwork = match ($appearance['glyph'].':'.($appearance['modifier'] ?? '')) {
        'user:magnifying-glass' => 'contact-find',
        'user:plus' => 'contact-create',
        'user:pencil-simple' => 'contact-update',
        'briefcase:plus' => 'lead-create',
        'briefcase:pencil-simple' => 'lead-update',
        'arrows-split:' => 'condition',
        'arrows-clockwise:' => 'loop',
        'brackets-curly:equals' => 'assign',
        'globe:' => 'http',
        'telegram-logo:' => 'telegram',
        'play:' => 'manual',
        default => null,
    };
@endphp
@if($appearance['glyph'] === 'code' && $appearance['modifier'] === null)
    <span class="workflow-node-glyph workflow-node-glyph--language" data-node-language="javascript" aria-hidden="true">JS</span>
@elseif($artwork)
    <span class="workflow-node-artwork" data-node-artwork="{{ $artwork }}" aria-hidden="true"
        x-data="typeof workflowNodeArtwork === 'function' ? workflowNodeArtwork() : {artworkLoaded: false}"
        data-artwork-ready="false" x-bind:data-artwork-ready="artworkLoaded ? 'true' : 'false'">
        <span class="workflow-node-glyph workflow-node-artwork__fallback {{ $appearance['modifier'] !== null ? 'workflow-node-glyph--action' : '' }} {{ $appearance['modifier'] === 'equals' ? 'workflow-node-glyph--assignment' : '' }}" x-show="!artworkLoaded">
            @if($appearance['modifier'] !== null)
                <x-filament::icon :icon="'wficon-'.$appearance['glyph'].($appearance['modifier'] === 'equals' ? '-bold' : '-fill')" class="workflow-node-glyph__entity"/>
                <x-filament::icon :icon="'wficon-'.$appearance['modifier'].'-bold'" class="workflow-node-glyph__action"/>
            @else
                <x-filament::icon :icon="'wficon-'.$appearance['glyph'].($appearance['glyph'] === 'telegram-logo' ? '-fill' : '-duotone')" class="workflow-node-glyph__main"/>
            @endif
        </span>
    </span>
@else
<span {{ $attributes->class(['workflow-node-glyph', 'workflow-node-glyph--action' => $appearance['modifier'] !== null, 'workflow-node-glyph--assignment' => $appearance['modifier'] === 'equals']) }} aria-hidden="true">
    @if($appearance['modifier'] !== null)
        <x-filament::icon :icon="'wficon-'.$appearance['glyph'].($appearance['modifier'] === 'equals' ? '-bold' : '-fill')" class="workflow-node-glyph__entity"/>
        <x-filament::icon :icon="'wficon-'.$appearance['modifier'].'-bold'" class="workflow-node-glyph__action"/>
    @else
        <x-filament::icon :icon="'wficon-'.$appearance['glyph'].($appearance['glyph'] === 'telegram-logo' ? '-fill' : '-duotone')" class="workflow-node-glyph__main"/>
    @endif
</span>
@endif
