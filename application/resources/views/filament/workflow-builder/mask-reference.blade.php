@php
    $masks = collect($groups)
        ->flatMap(fn (array $items, string $group): array => collect($items)
            ->map(fn (string $label, string $mask): array => [
                'group' => $group,
                'mask' => $mask,
                'path' => trim($mask, '{} '),
                'label' => $label,
            ])
            ->values()
            ->all())
        ->values()
        ->all();

    $systemIds = collect($systemIdGroups ?? [])
        ->flatMap(fn (array $items, string $group): array => collect($items)
            ->map(fn (array $item): array => [
                'group' => $group,
                'id' => (string) ($item['id'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
                'subtitle' => (string) ($item['subtitle'] ?? ''),
                'entity' => (string) ($item['entity'] ?? ''),
            ])
            ->filter(fn (array $item): bool => $item['id'] !== '')
            ->values()
            ->all())
        ->values()
        ->all();

    $systemIdGroupNames = collect($systemIdGroups ?? [])
        ->filter(fn (array $items): bool => count($items) > 0)
        ->keys()
        ->values()
        ->all();

    $popularMasks = [
        '{{lead.id}}',
        '{{lead.name}}',
        '{{lead.status_id}}',
        '{{lead.responsible_user_id}}',
        '{{contact.id}}',
        '{{contact.name}}',
        '{{item.id}}',
        '{{item.name}}',
        '{{event}}',
        '{{received_at}}',
    ];
@endphp

<div
    x-data="{
        query: '',
        copied: null,
        masks: @js($masks),
        systemIds: @js($systemIds),
        selectedSystemGroup: '',
        selectedFieldEntity: '',
        systemIdGroupNames: @js($systemIdGroupNames),
        popularMasks: @js($popularMasks),
        fieldEntities() {
            return [...new Set(this.systemIds
                .filter((item) => item.group === 'Поля amoCRM' && item.entity !== '')
                .map((item) => item.entity)
            )].sort();
        },
        filteredMasks() {
            const query = this.query.trim().toLowerCase();

            if (query === '') {
                return this.masks;
            }

            return this.masks.filter((mask) =>
                mask.group.toLowerCase().includes(query) ||
                mask.label.toLowerCase().includes(query) ||
                mask.mask.toLowerCase().includes(query) ||
                mask.path.toLowerCase().includes(query)
            );
        },
        filteredSystemIds() {
            const query = this.query.trim().toLowerCase();
            let items = this.systemIds;

            if (this.selectedSystemGroup === '') {
                return [];
            }

            items = items.filter((item) => item.group === this.selectedSystemGroup);

            if (this.selectedSystemGroup === 'Поля amoCRM' && this.selectedFieldEntity !== '') {
                items = items.filter((item) => item.entity === this.selectedFieldEntity);
            }

            if (query === '') {
                return items;
            }

            return items.filter((item) =>
                item.group.toLowerCase().includes(query) ||
                item.name.toLowerCase().includes(query) ||
                item.subtitle.toLowerCase().includes(query) ||
                item.entity.toLowerCase().includes(query) ||
                item.id.toLowerCase().includes(query)
            );
        },
        filteredGroups() {
            return this.filteredMasks().reduce((groups, mask) => {
                if (!groups[mask.group]) {
                    groups[mask.group] = [];
                }

                groups[mask.group].push(mask);

                return groups;
            }, {});
        },
        filteredSystemGroups() {
            return this.selectedSystemGroup === ''
                ? {}
                : { [this.selectedSystemGroup]: this.filteredSystemIds() };
        },
        copy(mask) {
            navigator.clipboard?.writeText(mask.mask);
            this.copied = mask.mask;
            setTimeout(() => this.copied = null, 1400);
        },
        copyId(item) {
            navigator.clipboard?.writeText(item.id);
            this.copied = item.id;
            setTimeout(() => this.copied = null, 1400);
        },
    }"
    class="workflow-mask-reference flex min-h-0 flex-col gap-4"
>
    <x-filament::input.wrapper>
        <x-filament::input
            type="search"
            x-model.debounce.150ms="query"
            placeholder="Поиск: сделка, поле, воронка, этап, status_id, lead.id..."
        />
    </x-filament::input.wrapper>

    <div class="space-y-2">
        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            Часто используются
        </div>

        <div class="flex flex-wrap gap-2">
            <template x-for="maskValue in popularMasks" :key="maskValue">
                <button
                    type="button"
                    x-on:click="copy(masks.find((mask) => mask.mask === maskValue) || { mask: maskValue })"
                    class="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 transition hover:border-primary-300 hover:text-primary-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-primary-700 dark:hover:text-primary-300"
                >
                    <span x-text="maskValue"></span>
                </button>
            </template>
        </div>
    </div>

    <div
        x-show="copied"
        x-cloak
        class="rounded-xl border border-success-200 bg-success-50 px-3 py-2 text-sm font-medium text-success-700 dark:border-success-900/50 dark:bg-success-950/30 dark:text-success-300"
    >
        Скопировано: <code x-text="copied"></code>
    </div>

    <section
        class="rounded-xl border border-amber-200 bg-amber-50/50 p-3 dark:border-amber-900/60 dark:bg-amber-950/10">
        <div class="mb-2 flex items-center justify-between gap-2">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Справочник ID</h3>
            <span
                x-show="selectedSystemGroup"
                x-cloak
                class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/40 dark:text-amber-300"
                x-text="filteredSystemIds().length"
            ></span>
        </div>

        <x-filament::input.wrapper>
            <x-filament::input.select x-model="selectedSystemGroup" x-on:change="selectedFieldEntity = ''">
                <option value="">Выберите тип ID</option>
                <template x-for="group in systemIdGroupNames" :key="group">
                    <option :value="group" x-text="group"></option>
                </template>
            </x-filament::input.select>
        </x-filament::input.wrapper>

        <div x-show="selectedSystemGroup === 'Поля amoCRM'" x-cloak class="mt-2">
            <x-filament::input.wrapper>
                <x-filament::input.select x-model="selectedFieldEntity">
                    <option value="">Все сущности</option>
                    <template x-for="entity in fieldEntities()" :key="entity">
                        <option :value="entity" x-text="entity"></option>
                    </template>
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>

        <div x-show="selectedSystemGroup" x-cloak class="mt-3 space-y-1.5">
            <template x-for="item in filteredSystemIds()" :key="selectedSystemGroup + item.id + item.name">
                <button
                    type="button"
                    x-on:click="copyId(item)"
                    class="group w-full rounded-lg border border-transparent px-2.5 py-2 text-left transition hover:border-amber-300 hover:bg-white/80 dark:hover:border-amber-800 dark:hover:bg-gray-900/70"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-950 dark:text-white" x-text="item.name"></div>
                            <div class="mt-0.5 text-xs text-slate-500 dark:text-gray-400" x-text="item.subtitle"></div>
                            <code class="mt-1 block break-all text-xs font-semibold text-amber-700 dark:text-amber-300">
                                ID: <span x-text="item.id"></span>
                            </code>
                        </div>

                        <span
                            class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-700 opacity-0 transition group-hover:opacity-100 dark:bg-amber-900/40 dark:text-amber-300">
                            копировать ID
                        </span>
                    </div>
                </button>
            </template>

            <div
                x-show="filteredSystemIds().length === 0"
                x-cloak
                class="rounded-lg border border-dashed border-amber-200 p-4 text-center text-sm text-amber-700 dark:border-amber-900/60 dark:text-amber-300"
            >
                Ничего не найдено в выбранном типе ID.
            </div>
        </div>
    </section>

    <div class="workflow-mask-reference-list min-h-0 flex-1 space-y-4 overflow-y-auto pr-1">
        <template x-for="[group, items] in Object.entries(filteredGroups())" :key="group">
            <section class="rounded-xl border border-slate-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                <div class="mb-2 flex items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white" x-text="group"></h3>
                    <span
                        class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500 dark:bg-gray-800 dark:text-gray-400"
                        x-text="items.length"></span>
                </div>

                <div class="space-y-1.5">
                    <template x-for="mask in items" :key="mask.mask">
                        <button
                            type="button"
                            x-on:click="copy(mask)"
                            class="group w-full rounded-lg border border-transparent px-2.5 py-2 text-left transition hover:border-primary-200 hover:bg-primary-50/60 dark:hover:border-primary-900 dark:hover:bg-primary-950/30"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="text-sm font-medium text-gray-950 dark:text-white"
                                         x-text="mask.label"></div>
                                    <code class="mt-0.5 block break-all text-xs text-slate-500 dark:text-gray-400"
                                          x-text="mask.mask"></code>
                                </div>

                                <span
                                    class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 opacity-0 transition group-hover:opacity-100 dark:bg-gray-800 dark:text-gray-400">
                                    копировать
                                </span>
                            </div>
                        </button>
                    </template>
                </div>
            </section>
        </template>

        <div
            x-show="Object.keys(filteredGroups()).length === 0 && Object.keys(filteredSystemGroups()).length === 0"
            x-cloak
            class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400"
        >
            Ничего не найдено.
        </div>
    </div>
</div>
