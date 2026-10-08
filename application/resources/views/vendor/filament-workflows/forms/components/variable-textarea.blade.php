@php
    $fieldWrapperView = $getFieldWrapperView();
    $extraAttributeBag = $getExtraAttributeBag();
    $isConcealed = $isConcealed();
    $isDisabled = $isDisabled();
    $isMonospace = $isMonospace();
    $rows = $getRows();
    $placeholder = $getPlaceholder();
    $shouldAutosize = $shouldAutosize();
    $statePath = $getStatePath();
    $initialHeight = (($rows ?? 2) * 1.5) + 0.75;
    $groupedVariables = $getGroupedVariables();
    $variableGroups = collect($groupedVariables)
        ->map(fn (array $items, string $category): array => [
            'category' => $category,
            'items' => collect($items)
                ->map(fn (array $variable): array => array_replace($variable, ['category' => $category]))
                ->values()
                ->all(),
        ])
        ->values()
        ->all();
@endphp

<x-dynamic-component
    :component="$fieldWrapperView"
    :field="$field"
    class="fi-fo-textarea-wrp"
>
    <div
        x-data="{
            showDropdown: false,
            searchQuery: '',
            selectedIndex: 0,
            groups: @js($variableGroups),
            popularPaths: [
                'item.id',
                'item.name',
                'lead.id',
                'lead.name',
                'lead.price',
                'lead.pipeline_id',
                'lead.status_id',
                'contact.id',
                'contact.name',
                'contact.tags',
                'status.status_id',
                'responsible.responsible_user_id',
            ],
            dropdownPosition: { top: 0, left: 0 },
            lastCursorPos: 0,

            allVariables() {
                return this.groups.flatMap((group) => group.items.map((variable) => ({
                    ...variable,
                    category: group.category,
                })));
            },

            groupVariables(variables) {
                return variables.reduce((groups, variable) => {
                    let group = groups.find((item) => item.category === variable.category);

                    if (!group) {
                        group = { category: variable.category, items: [] };
                        groups.push(group);
                    }

                    group.items.push(variable);

                    return groups;
                }, []);
            },

            filteredGroups() {
                const query = this.searchQuery.toLowerCase();
                const variables = this.allVariables();

                if (query.length === 0) {
                    return this.groupVariables(variables.filter((variable) => this.popularPaths.includes(variable.path)));
                }

                return this.groupVariables(
                    variables
                        .filter((variable) =>
                            variable.path.toLowerCase().includes(query) ||
                            variable.label.toLowerCase().includes(query) ||
                            (variable.description && variable.description.toLowerCase().includes(query)) ||
                            variable.category.toLowerCase().includes(query)
                        )
                        .slice(0, 24)
                );
            },

            flatFiltered() {
                return this.filteredGroups().flatMap((group) => group.items);
            },

            currentVariable() {
                return this.flatFiltered()[this.selectedIndex] || null;
            },

            isSelected(variable) {
                return this.currentVariable()?.path === variable.path;
            },

            saveCursorPosition() {
                const textarea = this.$refs.variableTextarea;
                if (textarea && document.activeElement === textarea) {
                    this.lastCursorPos = textarea.selectionStart;
                }
            },

            checkForVariableTrigger() {
                const textarea = this.$refs.variableTextarea;
                const value = textarea.value;
                const cursorPos = textarea.selectionStart;
                const openBrace = String.fromCharCode(123, 123);
                const closeBrace = String.fromCharCode(125, 125);
                const beforeCursor = value.substring(0, cursorPos);
                const lastOpenBrace = beforeCursor.lastIndexOf(openBrace);

                if (lastOpenBrace !== -1) {
                    const afterBrace = beforeCursor.substring(lastOpenBrace + 2);
                    if (!afterBrace.includes(closeBrace)) {
                        this.showDropdown = true;
                        this.searchQuery = afterBrace.trim();
                        this.selectedIndex = 0;
                        this.updateDropdownPosition(textarea, cursorPos);
                        return;
                    }
                }

                this.showDropdown = false;
            },

            updateDropdownPosition(textarea, cursorPos) {
                const computedStyle = window.getComputedStyle(textarea);
                const lineHeight = parseInt(computedStyle.lineHeight) || 24;
                const paddingTop = parseInt(computedStyle.paddingTop) || 0;
                const lines = textarea.value.substring(0, cursorPos).split('\\n');
                const currentLine = lines.length - 1;

                this.dropdownPosition = {
                    top: (currentLine * lineHeight) + paddingTop + lineHeight + 4,
                    left: 0,
                };
            },

            selectVariable(variable) {
                const textarea = this.$refs.variableTextarea;
                const value = textarea.value;
                const cursorPos = this.lastCursorPos || textarea.selectionStart;
                const openBrace = String.fromCharCode(123, 123);
                const closeBrace = String.fromCharCode(125, 125);
                const beforeCursor = value.substring(0, cursorPos);
                const lastOpenBrace = beforeCursor.lastIndexOf(openBrace);

                if (lastOpenBrace !== -1) {
                    const newValue = value.substring(0, lastOpenBrace) + openBrace + variable.path + closeBrace + value.substring(cursorPos);
                    textarea.value = newValue;
                    textarea.dispatchEvent(new Event('input', { bubbles: true }));

                    const newCursorPos = lastOpenBrace + variable.path.length + 4;
                    this.$nextTick(() => {
                        textarea.setSelectionRange(newCursorPos, newCursorPos);
                        textarea.focus();
                    });
                }

                this.showDropdown = false;
            },

            selectCurrent() {
                const variable = this.currentVariable();
                if (variable) {
                    this.selectVariable(variable);
                }
            },

            handleKeydown(event) {
                if (!this.showDropdown) return;

                const maxIndex = this.flatFiltered().length - 1;

                if (event.key === 'Escape') {
                    event.preventDefault();
                    this.showDropdown = false;
                } else if (event.key === 'Enter' && maxIndex >= 0) {
                    event.preventDefault();
                    this.selectCurrent();
                } else if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    this.selectedIndex = Math.min(this.selectedIndex + 1, maxIndex);
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    this.selectedIndex = Math.max(this.selectedIndex - 1, 0);
                }
            }
        }"
        x-on:click.outside="showDropdown = false"
        x-on:variable-input="checkForVariableTrigger()"
        x-on:variable-keydown="handleKeydown($event.detail.originalEvent)"
        x-on:variable-cursor="saveCursorPosition()"
        class="relative"
    >
        <x-filament::input.wrapper
            :disabled="$isDisabled"
            :valid="! $errors->has($statePath)"
            :attributes="
                \Filament\Support\prepare_inherited_attributes($extraAttributeBag)
                    ->class([
                        'fi-fo-textarea',
                        'fi-autosizable' => $shouldAutosize,
                    ])
            "
        >
            <div wire:ignore.self style="height: '{{ $initialHeight . 'rem' }}'">
                <textarea
                    x-ref="variableTextarea"
                    x-load
                    x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('textarea', 'filament/forms') }}"
                    x-data="textareaFormComponent({
                                initialHeight: @js($initialHeight),
                                shouldAutosize: @js($shouldAutosize),
                                state: $wire.$entangle('{{ $statePath }}'),
                            })"
                    @if ($shouldAutosize)
                        x-intersect.once="resize()"
                    x-on:resize.window="resize()"
                    @endif
                    x-model="state"
                    x-on:input="$dispatch('variable-input')"
                    x-on:focus="$dispatch('variable-input')"
                    x-on:keydown="$dispatch('variable-keydown', { originalEvent: $event })"
                    x-on:keyup="$dispatch('variable-cursor')"
                    x-on:click="$dispatch('variable-cursor')"
                    @if ($isGrammarlyDisabled())
                        data-gramm="false"
                    data-gramm_editor="false"
                    data-enable-grammarly="false"
                    @endif
                    {{ $getExtraAlpineAttributeBag() }}
                    {{
                        $getExtraInputAttributeBag()
                            ->merge([
                                'autocomplete' => $getAutocomplete(),
                                'autofocus' => $isAutofocused(),
                                'cols' => $getCols(),
                                'disabled' => $isDisabled,
                                'id' => $getId(),
                                'maxlength' => (! $isConcealed) ? $getMaxLength() : null,
                                'minlength' => (! $isConcealed) ? $getMinLength() : null,
                                'placeholder' => filled($placeholder) ? e($placeholder) : null,
                                'readonly' => $isReadOnly(),
                                'required' => $isRequired() && (! $isConcealed),
                                'rows' => $rows,
                                $applyStateBindingModifiers('wire:model') => $statePath,
                            ], escape: false)
                            ->class([
                                'font-mono text-sm' => $isMonospace,
                            ])
                    }}
                ></textarea>
            </div>
        </x-filament::input.wrapper>

        <div
            x-show="showDropdown && flatFiltered().length > 0"
            x-cloak
            x-transition:enter="transition ease-out duration-100"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            :style="'top: ' + dropdownPosition.top + 'px; left: ' + dropdownPosition.left + 'px;'"
            class="absolute z-[9999] w-full max-h-64 overflow-y-auto rounded-xl bg-white shadow-xl ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10"
        >
            <template x-for="group in filteredGroups()" :key="group.category">
                <div class="border-b border-slate-100 last:border-b-0 dark:border-gray-800">
                    <div
                        class="sticky top-0 bg-slate-50 px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:bg-gray-950 dark:text-gray-400"
                        x-text="group.category"></div>

                    <template x-for="variable in group.items" :key="variable.path">
                        <button
                            type="button"
                            x-on:mousedown.prevent="selectVariable(variable)"
                            :class="{ 'bg-primary-50 dark:bg-primary-900/20': isSelected(variable) }"
                            class="w-full px-3 py-2.5 text-left hover:bg-gray-50 dark:hover:bg-white/5 focus:outline-none"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 text-sm font-medium text-gray-950 dark:text-white"
                                     x-text="variable.label"></div>
                                <code
                                    class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-500 dark:bg-gray-800 dark:text-gray-400"
                                    x-text="variable.path"></code>
                            </div>
                        </button>
                    </template>
                </div>
            </template>
        </div>
    </div>
</x-dynamic-component>
