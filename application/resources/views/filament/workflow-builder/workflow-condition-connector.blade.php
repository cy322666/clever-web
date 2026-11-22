@php
    $logicPath = trim($getParentRepeater()->getContainer()->getStatePath().'.logic', '.');
@endphp
<div class="workflow-condition-connector fi-fo-field fi-fo-toggle-buttons-wrp" data-workflow-condition-connector
    x-data="{ logic: $wire.$entangle(@js($logicPath)) }" x-id="['condition-logic']">
    <div class="fi-fo-toggle-buttons fi-inline" role="group" aria-label="Связь между условиями">
        @foreach(['and' => 'И', 'or' => 'ИЛИ'] as $value => $label)
            <div class="fi-fo-toggle-buttons-btn-ctn">
                <input class="fi-fo-toggle-buttons-input" type="radio" value="{{ $value }}" x-model="logic"
                    :id="$id('condition-logic') + '-{{ $value }}'" :name="$id('condition-logic')" />
                <x-filament::button tag="label" x-bind:for="$id('condition-logic') + '-{{ $value }}'">{{ $label }}</x-filament::button>
            </div>
        @endforeach
    </div>
</div>
