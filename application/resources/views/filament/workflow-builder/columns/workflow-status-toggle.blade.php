@php
    $isActive = (bool) $record->is_active;
@endphp

<div @class([
    'workflow-list-status-control',
    'workflow-list-status-control--active' => $isActive,
    'workflow-list-status-control--inactive' => ! $isActive,
])>
    <x-filament::icon
        :icon="$isActive ? 'heroicon-s-bolt' : 'heroicon-o-power'"
        class="workflow-list-status-control__icon"
    />
</div>
