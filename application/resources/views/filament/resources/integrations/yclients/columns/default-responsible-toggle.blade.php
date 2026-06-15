@php
    $amoUserId = (int) $record->amo_user_id;
@endphp

<button
    type="button"
    wire:click="setDefaultResponsibleUser({{ $amoUserId }})"
    class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-sm font-medium transition
        {{ $isDefault
            ? 'bg-orange-100 text-orange-700 ring-1 ring-orange-300'
            : 'bg-gray-100 text-gray-500 hover:bg-orange-50 hover:text-orange-700' }}"
>
    <span
        class="h-2.5 w-2.5 rounded-full {{ $isDefault ? 'bg-orange-600' : 'bg-gray-300' }}"
        aria-hidden="true"
    ></span>

    {{ $isDefault ? 'Выбран' : 'Выбрать' }}
</button>
