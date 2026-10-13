@php
    $statePath = $getStatePath();
    $disabled = $isDisabled();
@endphp
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <x-filament::input.wrapper :disabled="$disabled" :valid="! $errors->has($statePath)" class="fi-fo-code-editor workflow-javascript-editor">
        <div
            x-load
            x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('code-editor', 'filament/forms') }}"
            x-data="codeEditorFormComponent({
                canWrap: false,
                isDisabled: @js($disabled),
                isLive: @js($isLive()),
                isLiveDebounced: @js($isLiveDebounced()),
                isLiveOnBlur: @js($isLiveOnBlur()),
                liveDebounce: @js($getLiveDebounce()),
                language: 'javascript',
                state: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')", isOptimisticallyLive: false) }},
            })"
            x-init="Object.assign($data, workflowJavascriptFormatting(@js($disabled)))"
            x-on:keydown.ctrl.shift.f.prevent.stop="formatCode()"
            x-on:keydown.meta.shift.f.prevent.stop="formatCode()"
            wire:ignore
            wire:key="{{ $getLivewireKey() }}.javascript.{{ (int) $disabled }}"
        >
            <div class="workflow-javascript-editor__toolbar">
                <span>JavaScript</span>
                <button type="button" x-on:click="formatCode()" x-bind:disabled="formatDisabled || formatting || !editor" @disabled($disabled)
                    title="Форматировать код (Ctrl/Cmd + Shift + F)" x-text="formatting ? 'Форматирование…' : 'Форматировать'">Форматировать</button>
            </div>
            <p class="workflow-javascript-editor__error" role="status" x-show="formatError" x-text="formatError" x-cloak></p>
            <div x-ref="editor" x-cloak aria-label="Код JavaScript"></div>
        </div>
    </x-filament::input.wrapper>
</x-dynamic-component>
