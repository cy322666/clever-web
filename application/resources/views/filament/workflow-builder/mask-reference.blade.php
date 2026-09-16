@php
    $reference = [];
    foreach ($groups as $group => $items) {
        if (str_contains($group, 'Воронк')) continue;
        foreach ($items as $expression => $label) {
            // Hide catalog-generated field IDs here; keep the full expression for copying.
            if (preg_match('/\.cf\((\d+)\)\}\}$/', $expression, $field)) {
                $suffix = ' · ID '.$field[1];
                if (str_ends_with($label, $suffix)) $label = substr($label, 0, -strlen($suffix));
            }
            $reference[$group][$expression] = ['value' => $expression, 'label' => $label, 'options' => []];
        }
    }
    foreach ($systemIdGroups ?? [] as $group => $items) {
        if (str_contains($group, 'Воронк')) continue;
        foreach ($items as $item) {
            $value = (string) ($item['id'] ?? '');
            if ($value === '') continue;
            $type = $group === 'Поля' ? 'Справочник ID · Поля · '.($item['entity'] ?? '') : $group;
            $reference[$type][$value] = ['value' => $value, 'label' => $item['name'] ?? $value, 'options' => array_values($item['options'] ?? [])];
        }
    }
    $reference['Модификаторы'] = [
        ['value' => '{{now:date(d.m.Y)}}', 'label' => 'Сегодня', 'options' => []],
        ['value' => '{{now:timestamp}}', 'label' => 'Текущее Unix-время', 'options' => []],
        ['value' => '{{now:add(1 day):timestamp}}', 'label' => 'Через день', 'options' => []],
        ['value' => '{{lead.name:trim}}', 'label' => 'Убрать пробелы', 'options' => []],
        ['value' => '{{lead.name:default(Без названия)}}', 'label' => 'Значение по умолчанию', 'options' => []],
        ['value' => '{{contact.phone:digits}}', 'label' => 'Только цифры', 'options' => []],
        ['value' => '{{lead.tags:join(, )}}', 'label' => 'Соединить список', 'options' => []],
        ['value' => '{{payload:json}}', 'label' => 'Тело webhook', 'options' => []],
    ];
    $reference = array_map('array_values', $reference);
@endphp
<div class="workflow-variable-browser" x-data="workflowVariableBrowser(@js($reference))">
    <label class="workflow-variable-browser__label">Тип переменной
        <select x-model="type" x-on:change="reset()" aria-label="Тип переменной">
            <option value="">Выберите тип…</option>
            @foreach(array_keys($reference) as $type)<option value="{{ $type }}">{{ $type }}</option>@endforeach
        </select>
    </label>
    <button x-show="selected" type="button" class="workflow-variable-browser__back" x-on:click="reset()"><x-filament::icon icon="heroicon-m-chevron-left" class="h-4 w-4"/><span x-text="selected?.label"></span></button>
    <input x-show="type" x-model="query" x-on:input="page = 0" type="search" placeholder="Найти…" aria-label="Найти переменную">
    <div class="workflow-variable-browser__items">
        <template x-for="item in visibleItems" :key="type + (selected?.value || '') + item.value">
            <div class="workflow-variable-browser__row">
                <button type="button" class="workflow-variable-browser__item" title="Копировать выражение" :title="'Копировать: ' + item.label" x-on:click="copy(item.value)">
                    <strong x-text="item.label"></strong>
                    <small x-show="copied === item.value" role="status">Скопировано</small>
                </button>
                <template x-if="item.options.length">
                    <button class="workflow-variable-browser__options" type="button" :aria-label="'Варианты: ' + item.label" title="Варианты значения" x-on:click="showOptions(item)"><x-filament::icon icon="heroicon-m-chevron-right" class="h-4 w-4"/></button>
                </template>
            </div>
        </template>
        <p x-show="type && !items.length">Ничего не найдено</p>
    </div>
    <nav class="workflow-variable-browser__pages" x-show="pageCount > 1" aria-label="Страницы справочника">
        <button type="button" :disabled="page === 0" x-on:click="page--" aria-label="Предыдущая страница"><x-filament::icon icon="heroicon-m-chevron-left" class="h-4 w-4"/></button>
        <span x-text="(page + 1) + ' / ' + pageCount"></span>
        <button type="button" :disabled="page + 1 >= pageCount" x-on:click="page++" aria-label="Следующая страница"><x-filament::icon icon="heroicon-m-chevron-right" class="h-4 w-4"/></button>
    </nav>
</div>
