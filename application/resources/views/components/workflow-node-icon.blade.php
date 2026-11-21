@props(['appearance'])
@php
    $glyph = $appearance['glyph'];
    $modifier = $appearance['modifier'];
    // These compound outlines are drawn as one symbol so the operations stay legible at canvas zoom.
    $outline = match ($glyph.':'.$modifier) {
        'arrows-split:' => 'branch',
        'briefcase:lightning' => 'event',
        'briefcase:magnifying-glass' => 'lead-find',
        'briefcase:plus' => 'lead-create',
        'user:magnifying-glass' => 'contact-find',
        'webhooks-logo:' => 'webhook',
        default => null,
    };
    $outlinedEntity = in_array($glyph, ['bell', 'robot', 'telegram-logo'], true) ? $glyph.'-outline' : $glyph.'-duotone';
@endphp
@if($glyph === 'code' && $modifier === null)
    <span class="workflow-node-glyph workflow-node-glyph--language" data-node-language="javascript" aria-hidden="true">JS</span>
@elseif($outline)
    <span class="workflow-node-glyph" data-node-outline="{{ $outline }}" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            @switch($outline)
                @case('branch')
                    <path d="M12 4v7a5 5 0 0 0 5 5h3M8 8l4-4 4 4m1 5 3 3-3 3M10 20v-2a4 4 0 0 0-4-4H4m3-3-3 3 3 3"/>
                    @break
                @case('event')
                    <rect x="3.5" y="7" width="13" height="10" rx="2"/>
                    <path d="M7.5 7V5h5v2M4 11h12m3-1-4 5h3l-1 4 5-6h-3z"/>
                    @break
                @case('lead-find')
                    <path d="M13 17H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h13a2 2 0 0 1 2 2v2M8 6V3.5h7V6M3 10h12"/>
                    <circle cx="16.5" cy="14" r="3.5"/><path d="m19 17 3 3"/>
                    @break
                @case('lead-create')
                    <path d="M13 18H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h13a2 2 0 0 1 2 2v3M8 6V3.5h7V6M3 10h17m-2 3v8m-4-4h8"/>
                    @break
                @case('contact-find')
                    <circle cx="8.5" cy="6.5" r="3.2"/>
                    <path d="M3 19v-1a4.5 4.5 0 0 1 4.5-4.5h2"/><circle cx="16.5" cy="14" r="3.5"/><path d="m19 17 3 3"/>
                    @break
                @case('webhook')
                    <path d="M12 7a3 3 0 1 0-3-3m3 3-4 8m5-8 4 8M7 15a3 3 0 1 0 2 4m0-1h7m0 1a3 3 0 1 0 1-4"/><circle cx="12" cy="4" r="1"/>
                    @break
            @endswitch
        </svg>
    </span>
@else
    <span {{ $attributes->class(['workflow-node-glyph', 'workflow-node-glyph--action' => $modifier !== null, 'workflow-node-glyph--assignment' => $modifier === 'equals']) }} aria-hidden="true">
        @if($modifier !== null)
            <x-filament::icon :icon="'wficon-'.($modifier === 'equals' ? 'brackets-curly-bold' : $outlinedEntity)" class="workflow-node-glyph__entity"/>
            <x-filament::icon :icon="'wficon-'.$modifier.'-bold'" class="workflow-node-glyph__action"/>
        @else
            <x-filament::icon :icon="'wficon-'.$outlinedEntity" class="workflow-node-glyph__main"/>
        @endif
    </span>
@endif
