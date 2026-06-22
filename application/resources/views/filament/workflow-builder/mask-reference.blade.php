@php
    $hiddenReferenceGroups = [
        'Беседа / чат',
        'Задача',
        'Компания',
        'Контакт',
        'Покупатель',
        'Примечание',
        'Сделка',
        'Поля компании',
        'Поля контакта',
        'Поля покупателя',
        'Поля сделки',
        'Воронка',
    ];

    $masks = collect($groups)
        ->reject(fn (array $items, string $group): bool => in_array($group, $hiddenReferenceGroups, true))
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
                'options' => array_values($item['options'] ?? []),
                'kind' => (string) ($item['kind'] ?? 'id'),
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

    $modifierGroups = [
        [
            'title' => 'Дата и время',
            'items' => [
                ['expression' => '{{now:date(d.m.Y)}}', 'label' => 'Сегодня в формате 19.06.2026'],
                ['expression' => '{{now:datetime(d.m.Y H:i)}}', 'label' => 'Дата и время в формате 19.06.2026 14:30'],
                ['expression' => '{{now:timestamp}}', 'label' => 'Unix timestamp'],
                ['expression' => '{{now:add(1 day):date(d.m.Y)}}', 'label' => 'Завтра'],
                ['expression' => '{{now:add(30 minutes):datetime(d.m.Y H:i)}}', 'label' => 'Через 30 минут'],
                ['expression' => '{{lead.created_at:date(d.m.Y)}}', 'label' => 'Дата создания сделки'],
            ],
        ],
        [
            'title' => 'Текст',
            'items' => [
                ['expression' => '{{lead.name:trim}}', 'label' => 'Убрать пробелы по краям'],
                ['expression' => '{{lead.name:upper}}', 'label' => 'В верхний регистр'],
                ['expression' => '{{lead.name:lower}}', 'label' => 'В нижний регистр'],
                ['expression' => '{{lead.name:default(Без названия)}}', 'label' => 'Значение, если пусто'],
            ],
        ],
        [
            'title' => 'Телефон и числа',
            'items' => [
                ['expression' => '{{contact.phone:digits}}', 'label' => 'Оставить только цифры'],
                ['expression' => '{{contact.phone:phone_ru}}', 'label' => 'Привести номер к 79991234567'],
                ['expression' => '{{lead.price:number(0)}}', 'label' => 'Число без копеек'],
                ['expression' => '{{lead.price:number(2)}}', 'label' => 'Число с двумя знаками'],
            ],
        ],
        [
            'title' => 'Списки и webhook',
            'items' => [
                ['expression' => '{{lead.tags:join(, )}}', 'label' => 'Список тегов через запятую'],
                ['expression' => '{{payload:json}}', 'label' => 'Тело webhook как JSON'],
                ['expression' => '{{payload.client.phone:digits}}', 'label' => 'Телефон из webhook без лишних символов'],
            ],
        ],
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
        expandedFields: {},
        expandedMaskGroups: {},
        systemIdGroupNames: @js($systemIdGroupNames),
        popularMasks: @js($popularMasks),
        modifierGroups: @js($modifierGroups),
        fieldEntities() {
            return [...new Set(this.systemIds
                .filter((item) => item.group === 'Поля' && item.entity !== '')
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

            if (this.selectedSystemGroup === 'Поля' && this.selectedFieldEntity !== '') {
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
                item.id.toLowerCase().includes(query) ||
                item.options.some((option) =>
                    option.name.toLowerCase().includes(query) ||
                    option.id.toLowerCase().includes(query)
                )
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
        filteredModifierGroups() {
            const query = this.query.trim().toLowerCase();

            return this.modifierGroups
                .map((group) => ({
                    ...group,
                    items: query === ''
                        ? group.items
                        : group.items.filter((item) =>
                            group.title.toLowerCase().includes(query) ||
                            item.label.toLowerCase().includes(query) ||
                            item.expression.toLowerCase().includes(query)
                        ),
                }))
                .filter((group) => group.items.length > 0);
        },
        isMaskGroupOpen(group) {
            return this.query.trim() !== '' || this.expandedMaskGroups[group] === true;
        },
        toggleMaskGroup(group) {
            this.expandedMaskGroups[group] = !this.expandedMaskGroups[group];
        },
        copy(mask) {
            navigator.clipboard?.writeText(mask.mask);
            this.copied = mask.mask;
            setTimeout(() => this.copied = null, 1400);
        },
        copyExpression(expression) {
            navigator.clipboard?.writeText(expression);
            this.copied = expression;
            setTimeout(() => this.copied = null, 1400);
        },
        copyId(item) {
            navigator.clipboard?.writeText(item.id);
            this.copied = item.id;
            setTimeout(() => this.copied = null, 1400);
        },
        toggleFieldOptions(item) {
            this.expandedFields[item.id] = !this.expandedFields[item.id];
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
                    class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 transition hover:border-primary-300 hover:text-primary-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-primary-700 dark:hover:text-primary-300"
                >
                    <span x-text="maskValue"></span>
                </button>
            </template>
        </div>
    </div>

    <div
        x-show="copied"
        x-cloak
        class="rounded-md border border-success-200 bg-success-50 px-3 py-2 text-sm font-medium text-success-700 dark:border-success-900/50 dark:bg-success-950/30 dark:text-success-300"
    >
        Скопировано: <code x-text="copied"></code>
    </div>

    <section
        class="rounded-md border border-amber-200 bg-amber-50/50 p-3 dark:border-amber-900/60 dark:bg-amber-950/10">
        <div class="mb-2 flex items-center justify-between gap-2">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Справочник ID</h3>
            <span
                x-show="selectedSystemGroup"
                x-cloak
                class="rounded-md bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/40 dark:text-amber-300"
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

        <div x-show="selectedSystemGroup === 'Поля'" x-cloak class="mt-2">
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
                <div
                    class="group w-full rounded-md border border-transparent px-2.5 py-2 transition hover:border-amber-300 hover:bg-white/80 dark:hover:border-amber-800 dark:hover:bg-gray-900/70"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-950 dark:text-white" x-text="item.name"></div>
                            <div class="mt-0.5 text-xs text-slate-500 dark:text-gray-400" x-text="item.subtitle"></div>
                            <code class="mt-1 block break-all text-xs font-semibold text-amber-700 dark:text-amber-300">
                                <span x-text="item.kind === 'variable' ? 'Переменная:' : 'ID:'"></span>
                                <span x-text="item.id"></span>
                            </code>
                        </div>

                        <div class="flex shrink-0 items-center gap-1 self-end">
                            <button
                                x-show="item.options.length > 0"
                                type="button"
                                x-on:click.stop="toggleFieldOptions(item)"
                                class="rounded-md p-1.5 text-amber-600 transition hover:bg-amber-100 hover:text-amber-800 dark:text-amber-300 dark:hover:bg-amber-900/40"
                                :title="selectedSystemGroup === 'Воронки'
                                    ? (expandedFields[item.id] ? 'Скрыть этапы' : 'Показать этапы')
                                    : (expandedFields[item.id] ? 'Скрыть варианты' : 'Показать варианты')"
                            >
                                <x-filament::icon
                                    icon="heroicon-o-list-bullet"
                                    class="h-4 w-4"
                                    x-bind:class="{ 'text-amber-900 dark:text-amber-100': expandedFields[item.id] }"
                                />
                            </button>

                            <button
                                type="button"
                                x-on:click.stop="copyId(item)"
                                class="rounded-md p-1.5 text-amber-600 transition hover:bg-amber-100 hover:text-amber-800 dark:text-amber-300 dark:hover:bg-amber-900/40"
                                :title="item.kind === 'variable'
                                    ? 'Копировать переменную'
                                    : (selectedSystemGroup === 'Воронки' ? 'Копировать ID воронки' : 'Копировать ID')"
                            >
                                <x-filament::icon icon="heroicon-o-clipboard-document" class="h-4 w-4"/>
                            </button>
                        </div>
                    </div>

                    <div
                        x-show="item.options.length > 0 && expandedFields[item.id]"
                        x-collapse
                        class="mt-2 border-t border-amber-200 pt-2 dark:border-amber-900/60"
                    >
                        <div
                            class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">
                            <span
                                x-text="selectedSystemGroup === 'Воронки' ? 'Этапы воронки' : 'Варианты поля'"></span>
                        </div>

                        <div class="space-y-1">
                            <template x-for="option in item.options" :key="item.id + option.id">
                                <button
                                    type="button"
                                    x-on:click.stop="copyId(option)"
                                    class="flex w-full items-center justify-between gap-3 rounded-md px-2 py-1.5 text-left transition hover:bg-amber-100/70 dark:hover:bg-amber-900/30"
                                    :title="selectedSystemGroup === 'Воронки' ? 'Копировать ID этапа' : 'Копировать ID варианта'"
                                >
                                    <span class="min-w-0 truncate text-xs font-medium text-gray-800 dark:text-gray-200"
                                          x-text="option.name"></span>
                                    <code class="shrink-0 text-[11px] font-semibold text-amber-700 dark:text-amber-300">
                                        ID: <span x-text="option.id"></span>
                                    </code>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>
            </template>

            <div
                x-show="filteredSystemIds().length === 0"
                x-cloak
                class="rounded-md border border-dashed border-amber-200 p-4 text-center text-sm text-amber-700 dark:border-amber-900/60 dark:text-amber-300"
            >
                Ничего не найдено в выбранном типе ID.
            </div>
        </div>
    </section>

    <div class="workflow-mask-reference-list min-h-0 flex-1 space-y-4 overflow-y-auto pr-1">
        <section class="rounded-md border border-amber-200 bg-amber-50/70 p-3 dark:border-amber-900/60 dark:bg-amber-950/20">
            <button
                type="button"
                x-on:click="toggleMaskGroup('Модификаторы')"
                class="flex w-full items-center justify-between gap-2 text-left"
            >
                <span class="text-sm font-semibold text-gray-950 dark:text-white">Модификаторы</span>

                <span class="flex items-center gap-2">
                    <span
                        class="rounded-md bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/40 dark:text-amber-300"
                        x-text="filteredModifierGroups().reduce((count, group) => count + group.items.length, 0)"></span>
                    <x-filament::icon
                        icon="heroicon-o-chevron-down"
                        class="h-4 w-4 text-amber-500 transition dark:text-amber-400"
                        x-bind:class="{ 'rotate-180': isMaskGroupOpen('Модификаторы') }"
                    />
                </span>
            </button>

            <div
                x-show="isMaskGroupOpen('Модификаторы')"
                x-collapse
                class="mt-3 space-y-4"
            >
                <template x-for="group in filteredModifierGroups()" :key="group.title">
                    <div>
                        <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300"
                             x-text="group.title"></div>

                        <div class="space-y-1.5">
                            <template x-for="item in group.items" :key="item.expression">
                                <button
                                    type="button"
                                    x-on:click="copyExpression(item.expression)"
                                    class="group w-full rounded-md border border-transparent px-2.5 py-2 text-left transition hover:border-amber-300 hover:bg-white dark:hover:border-amber-800 dark:hover:bg-gray-900/70"
                                >
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <div class="text-sm font-medium text-gray-950 dark:text-white"
                                                 x-text="item.label"></div>
                                            <code class="mt-0.5 block break-all text-xs text-amber-700 dark:text-amber-300"
                                                  x-text="item.expression"></code>
                                        </div>

                                        <span
                                            class="shrink-0 rounded-md bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-700 opacity-0 transition group-hover:opacity-100 dark:bg-amber-900/40 dark:text-amber-300">
                                            копировать
                                        </span>
                                    </div>
                                </button>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </section>

        <template x-for="[group, items] in Object.entries(filteredGroups())" :key="group">
            <section class="rounded-md border border-slate-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                <button
                    type="button"
                    x-on:click="toggleMaskGroup(group)"
                    class="flex w-full items-center justify-between gap-2 text-left"
                >
                    <span class="text-sm font-semibold text-gray-950 dark:text-white" x-text="group"></span>

                    <span class="flex items-center gap-2">
                        <span
                            class="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500 dark:bg-gray-800 dark:text-gray-400"
                            x-text="items.length"></span>
                        <x-filament::icon
                            icon="heroicon-o-chevron-down"
                            class="h-4 w-4 text-slate-400 transition dark:text-gray-500"
                            x-bind:class="{ 'rotate-180': isMaskGroupOpen(group) }"
                        />
                    </span>
                </button>

                <div
                    x-show="isMaskGroupOpen(group)"
                    x-collapse
                    class="mt-2 space-y-1.5"
                >
                    <template x-for="mask in items" :key="mask.mask">
                        <button
                            type="button"
                            x-on:click="copy(mask)"
                            class="group w-full rounded-md border border-transparent px-2.5 py-2 text-left transition hover:border-primary-200 hover:bg-primary-50/60 dark:hover:border-primary-900 dark:hover:bg-primary-950/30"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="text-sm font-medium text-gray-950 dark:text-white"
                                         x-text="mask.label"></div>
                                    <code class="mt-0.5 block break-all text-xs text-slate-500 dark:text-gray-400"
                                          x-text="mask.mask"></code>
                                </div>

                                <span
                                    class="shrink-0 rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 opacity-0 transition group-hover:opacity-100 dark:bg-gray-800 dark:text-gray-400">
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
            class="rounded-md border border-dashed border-slate-300 p-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400"
        >
            Ничего не найдено.
        </div>
    </div>
</div>
