@php
    $reference = [];
    $fieldMetadata = [];
    foreach (($systemIdGroups['Поля'] ?? []) as $field) {
        $id = (string) ($field['id'] ?? '');
        if ($id === '') continue;
        $entity = (string) ($field['entity'] ?? '');
        $fieldMetadata[$entity.'|'.$id] = $field;
        $fieldMetadata['*|'.$id] ??= $field;
    }
    $maskEntities = ['lead' => 'Сделка', 'contact' => 'Контакт', 'company' => 'Компания', 'customer' => 'Покупатель'];
    foreach ($groups as $group => $items) {
        if (str_contains($group, 'Воронк')) continue;
        foreach ($items as $expression => $label) {
            $id = null;
            $options = [];
            if (preg_match('/\.cf\((\d+)\)\}\}$/', $expression, $field)) {
                $id = $field[1];
                $suffix = ' · ID '.$field[1];
                if (str_ends_with($label, $suffix)) $label = substr($label, 0, -strlen($suffix));
                preg_match('/^\{\{([a-z_]+)\.cf\(/', $expression, $mask);
                $entity = $maskEntities[$mask[1] ?? ''] ?? '';
                $metadata = $fieldMetadata[$entity.'|'.$id] ?? $fieldMetadata['*|'.$id] ?? null;
                $options = array_values($metadata['options'] ?? []);
            }
            $reference[$group][$expression] = ['value' => $expression, 'id' => $id, 'label' => $label, 'options' => $options];
        }
    }
    foreach ($systemIdGroups ?? [] as $group => $items) {
        if ($group === 'Поля' || str_contains($group, 'Воронк')) continue;
        foreach ($items as $item) {
            $value = (string) ($item['id'] ?? '');
            if ($value === '') continue;
            if (($item['kind'] ?? '') === 'variable') {
                $reference[$group][$value] ??= ['value' => $value, 'id' => null, 'label' => $item['name'] ?? $value, 'options' => []];
                continue;
            }
            $reference[$group]['id:'.$value] = ['value' => '', 'id' => $value, 'label' => $item['name'] ?? $value, 'options' => array_values($item['options'] ?? [])];
        }
    }
    $reference['Модификаторы'] = [
        ['value' => '{{now:date(d.m.Y)}}', 'id' => null, 'label' => 'Сегодня', 'options' => []],
        ['value' => '{{now:timestamp}}', 'id' => null, 'label' => 'Текущее Unix-время', 'options' => []],
        ['value' => '{{now:add(1 day):timestamp}}', 'id' => null, 'label' => 'Через день', 'options' => []],
        ['value' => '{{lead.name:trim}}', 'id' => null, 'label' => 'Убрать пробелы', 'options' => []],
        ['value' => '{{lead.name:default(Без названия)}}', 'id' => null, 'label' => 'Значение по умолчанию', 'options' => []],
        ['value' => '{{contact.phone:digits}}', 'id' => null, 'label' => 'Только цифры', 'options' => []],
        ['value' => '{{lead.tags:join(, )}}', 'id' => null, 'label' => 'Соединить список', 'options' => []],
        ['value' => '{{payload:json}}', 'id' => null, 'label' => 'Тело webhook', 'options' => []],
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
    <section x-cloak x-show="active" class="workflow-variable-browser__details" aria-live="polite">
        <header>
            <strong x-text="active?.label"></strong>
            <button type="button" x-on:click="active = null" aria-label="Закрыть карточку"><x-filament::icon icon="heroicon-m-x-mark" class="h-4 w-4"/></button>
        </header>
        <div x-show="active?.id" class="workflow-variable-browser__detail">
            <span>ID</span><code x-text="active?.id"></code>
            <button type="button" x-on:click="copy(active.id)" x-text="copied === active?.id ? 'Скопировано' : 'Копировать'"></button>
        </div>
        <div x-show="active?.value" class="workflow-variable-browser__detail">
            <span>Переменная</span><code x-text="active?.value"></code>
            <button type="button" x-on:click="copy(active.value)" x-text="copied === active?.value ? 'Скопировано' : 'Копировать'"></button>
        </div>
        <button x-show="active?.options?.length" type="button" class="workflow-variable-browser__show-options" x-on:click="showOptions(active)">Показать значения поля</button>
    </section>
    <div class="workflow-variable-browser__items">
        <template x-for="item in visibleItems" :key="type + (selected?.value || '') + (item.id || item.value)">
            <div class="workflow-variable-browser__row">
                <button type="button" class="workflow-variable-browser__item" :title="'Показать ID и переменную: ' + item.label" x-on:click="showDetails(item)">
                    <strong x-text="item.label"></strong>
                    <x-filament::icon icon="heroicon-m-chevron-right" class="h-4 w-4"/>
                </button>
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
