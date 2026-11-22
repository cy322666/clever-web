@props(['appearance'])
@php
    $variant = match ($appearance['glyph']) {
        'bell', 'robot' => 'outline',
        'telegram-logo' => 'brand',
        'brackets-curly' => 'bold',
        default => 'duotone',
    };
@endphp
@if($appearance['glyph'] === 'code')
    <span class="workflow-node-glyph workflow-node-glyph--language" data-node-language="javascript" aria-hidden="true">JS</span>
@else
    <span {{ $attributes->class(['workflow-node-glyph', 'workflow-node-glyph--minimal']) }} aria-hidden="true">
        <x-filament::icon :icon="'wficon-'.$appearance['glyph'].'-'.$variant" class="workflow-node-glyph__main"/>
    </span>
@endif
