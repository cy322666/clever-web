{{-- The same field widgets and classes as the production forms. State is local;
     validation and execution still use the original PHP configuration schemas. --}}
<div data-local-inspector wire:ignore x-show="typeof local !== 'undefined' && local" x-cloak>
    <form class="workflow-inspector__form" x-on:submit.prevent="closeInspector()">
        <div class="workflow-inspector__fields workflow-inspector__workspace">
            <div class="workflow-inspector__pane workflow-node-settings__parameters fi-sc fi-grid" role="tabpanel" aria-label="Настройки" x-show="tab === 'settings'" data-local-inspector-fields></div>
            <div class="workflow-inspector__pane" role="tabpanel" aria-label="Вход" x-show="tab === 'input'" data-local-inspector-input></div>
            <div class="workflow-inspector__pane" role="tabpanel" aria-label="Выход" x-show="tab === 'output'">
                @include('filament.workflow-builder.workflow-json-tree', ['expression'=>"typeof local !== 'undefined' ? local?.output() ?? null : null"])
            </div>
        </div>
        <footer class="workflow-inspector__footer">
            <button type="button" x-on:click="closeInspector(false)">Отменить</button>
            <button type="button" x-on:click="closeInspector()">Применить</button>
        </footer>
    </form>

    <template data-local-inspector-template="value">
        <div class="workflow-value-field fi-fo-field" x-id="['local-value']" x-on:workflow-local-field-refresh="refreshField()"
            x-on:workflow-expression-field="if (!field.relativePath) { mode='expression'; $nextTick(()=>$refs.value.focus()); }"
            x-on:workflow-expression-suggestions="sources=$event.detail.sources; customFields=$event.detail.customFields || []; suggest({target:$refs.value})"
            x-on:input="suggest($event)" x-on:keydown="suggestionKey($event)" x-on:click="if ($event.target === $refs.value) suggest($event)"
            x-on:keyup="if (['ArrowLeft','ArrowRight','Home','End'].includes($event.key)) suggest($event)" x-on:click.outside="suggestions=[]">
            <div class="workflow-value-field__header" :class="{'workflow-value-field__header--unlabeled':labelHidden}">
                <label x-show="!labelHidden" :for="$id('local-value')"><span x-text="label"></span><span x-show="required" aria-hidden="true"> *</span></label>
                <div class="workflow-value-field__modes" role="group" aria-label="Способ задания значения" x-show="!field.relativePath">
                    <button type="button" :aria-pressed="mode === 'value'" x-on:click="mode='value'; if (String(state ?? '').includes('{'+'{')) state=''">Вручную</button>
                    <button type="button" :aria-pressed="mode === 'expression'" x-on:click="mode='expression'; $nextTick(()=>$refs.value.focus())">Переменная</button>
                </div>
            </div>
            <x-filament::input.wrapper x-show.important="mode === 'value' && hasOptions">
                <select class="fi-input workflow-value-field__select" x-model="state" :id="$id('local-value')" :aria-label="label">
                    <option value="" x-text="placeholder || (Object.keys(options).length ? 'Выберите значение' : 'Нет доступных вариантов, обновите справочник')"></option>
                    <template x-if="state !== null && state !== '' && !String(state).includes('{'+'{') && !Object.hasOwn(options,state)"><option :value="state" x-text="state"></option></template>
                    <template x-for="(option, value) in options" :key="value"><option :value="value" :selected="String(state) === String(value)" x-text="option"></option></template>
                </select>
            </x-filament::input.wrapper>
            <x-filament::input.wrapper x-show.important="mode === 'expression' || !hasOptions">
                <template x-if="!field.rows"><input x-ref="value" x-model="state" class="fi-input" data-workflow-value-input
                    :data-workflow-filter-item-field="field.relativePath ? '' : null" :id="$id('local-value')+'-text'"
                    :type="mode === 'expression' ? 'text' : field.type || 'text'" :aria-label="label" :placeholder="placeholder"
                    x-on:dragover.prevent x-on:drop.prevent="const raw=$event.dataTransfer.getData('application/workflow-expression'); if(raw && !field.relativePath) {state=JSON.parse(raw).expression;mode='expression';}" /></template>
                <template x-if="field.rows"><textarea x-ref="value" x-model="state" class="fi-input" data-workflow-value-input :rows="field.rows"
                    :id="$id('local-value')+'-text'" :aria-label="label" :placeholder="placeholder"
                    x-on:dragover.prevent x-on:drop.prevent="const raw=$event.dataTransfer.getData('application/workflow-expression'); if(raw) {state=JSON.parse(raw).expression;mode='expression';}"></textarea></template>
            </x-filament::input.wrapper>
            <div class="workflow-value-suggestions" x-show="suggestions.length" x-cloak role="listbox" aria-label="Подсказки переменных">
                <template x-for="(item,index) in suggestions" :key="item.expression"><button type="button" role="option" :aria-selected="index === suggestionIndex" x-on:mousedown.prevent x-on:click="chooseSuggestion(item)"><span x-text="item.source+' · '+(item.label || item.path)"></span><code x-text="item.expression"></code></button></template>
            </div>
            <small class="fi-fo-field-wrp-helper-text" x-show="helper" x-text="helper"></small>
        </div>
    </template>
    <template data-local-inspector-template="select">
        <div class="fi-fo-field fi-fo-select-wrp" x-on:workflow-local-field-refresh="refreshField()" x-id="['local-select']">
            <label class="fi-fo-field-label-content" x-show="!labelHidden" :for="$id('local-select')"><span x-text="label"></span><span x-show="required" aria-hidden="true"> *</span></label>
            <x-filament::input.wrapper class="fi-fo-select">
                <select class="fi-select-input fi-input" x-show="field.native && !field.searchable && !field.multiple" x-model="state" :id="$id('local-select')" :aria-label="label">
                    <option value="" x-text="placeholder || 'Выберите значение'"></option>
                    <template x-for="(option,value) in options" :key="value"><option :value="value" :selected="String(state) === String(value)" x-text="option"></option></template>
                </select>
                <div data-local-select class="fi-select-input" x-show="!field.native || field.searchable || field.multiple" x-on:keydown.esc="select?.dropdown?.isActive && $event.stopPropagation()" x-on:workflow-local-select-refresh="refreshLocalSelect()"><div x-ref="select"></div></div>
            </x-filament::input.wrapper>
            <button type="button" class="fi-icon-btn fi-size-sm" aria-label="Добавить подключение" title="Добавить подключение" x-show="field.canCreate" x-on:click="local.createOption()"><x-filament::icon icon="heroicon-o-plus" class="h-4 w-4"/></button>
            <small class="fi-fo-field-wrp-helper-text" x-show="helper" x-text="helper"></small>
        </div>
    </template>
    <template data-local-inspector-template="text">
        <div class="fi-fo-field" x-on:workflow-local-field-refresh="refreshField()" x-id="['local-text']">
            <label class="fi-fo-field-label-content" x-show="!labelHidden" :for="$id('local-text')"><span x-text="label"></span><span x-show="required" aria-hidden="true"> *</span></label>
            <x-filament::input.wrapper><input class="fi-input" x-model="state" :type="field.type || 'text'" :id="$id('local-text')" :aria-label="label" :placeholder="placeholder" /></x-filament::input.wrapper>
            <small class="fi-fo-field-wrp-helper-text" x-show="helper" x-text="helper"></small>
        </div>
    </template>
    <template data-local-inspector-template="textarea">
        <div class="fi-fo-field" x-on:workflow-local-field-refresh="refreshField()">
            <label class="fi-fo-field-label-content" x-show="!labelHidden" x-text="label"></label>
            <x-filament::input.wrapper><textarea class="fi-input" x-model="state" :rows="field.rows || 4" :aria-label="label" :placeholder="placeholder"></textarea></x-filament::input.wrapper>
            <small class="fi-fo-field-wrp-helper-text" x-show="helper" x-text="helper"></small>
        </div>
    </template>
    <template data-local-inspector-template="buttons">
        <div class="fi-fo-field fi-fo-toggle-buttons-wrp" x-id="['local-buttons']" x-on:workflow-local-field-refresh="refreshField()">
            <label class="fi-fo-field-label-content" x-show="!labelHidden" x-text="label"></label>
            <div class="fi-fo-toggle-buttons fi-inline" role="group" :aria-label="label"><template x-for="(option,value) in options" :key="value"><div class="fi-fo-toggle-buttons-btn-ctn">
                <input class="fi-fo-toggle-buttons-input" type="radio" :id="$id('local-buttons')+'-'+value" :name="$id('local-buttons')" :value="value" x-model="state" />
                <x-filament::button tag="label" x-bind:for="$id('local-buttons')+'-'+value"><span x-text="option"></span></x-filament::button>
            </div></template></div>
            <small class="fi-fo-field-wrp-helper-text" x-show="helper" x-text="helper"></small>
        </div>
    </template>
    <template data-local-inspector-template="toggle">
        <div class="fi-fo-field" x-on:workflow-local-field-refresh="refreshField()"><label style="display:flex;align-items:center;gap:10px">
            <button type="button" role="switch" class="fi-toggle fi-fo-toggle" :aria-label="label" :aria-checked="Boolean(state).toString()" x-on:click="state=!state"
                :class="state ? @js('fi-toggle-on '.implode(' ', \Filament\Support\get_component_color_classes(\Filament\Support\View\Components\ToggleComponent::class,'primary'))) : 'fi-toggle-off'">
                <div><div aria-hidden="true"></div><div aria-hidden="true"></div></div>
            </button><span x-text="label"></span></label><small class="fi-fo-field-wrp-helper-text" x-show="helper" x-text="helper"></small></div>
    </template>
    <template data-local-inspector-template="headers">
        <div class="workflow-headers-field fi-fo-field" x-on:workflow-local-field-refresh="refreshField()">
            <div class="workflow-value-field__header"><span x-text="label"></span><div class="workflow-value-field__modes" role="group" aria-label="Формат заголовков"><button type="button" :aria-pressed="mode === 'fields'" x-on:click="setMode('fields')">Поля</button><button type="button" :aria-pressed="mode === 'json'" x-on:click="setMode('json')">JSON</button></div></div>
            <div x-show="mode === 'fields'" class="workflow-headers-field__rows"><template x-for="row in rows" :key="row.id"><div class="workflow-headers-field__row">
                <label>Название<x-filament::input.wrapper><input class="fi-input" x-model="row.name" x-on:input="syncRows()" placeholder="Authorization" aria-label="Название заголовка" /></x-filament::input.wrapper></label>
                <label>Значение<x-filament::input.wrapper><input class="fi-input" x-model="row.value" x-on:input="syncRows()" data-workflow-value-input placeholder="Значение или переменная" aria-label="Значение заголовка" /></x-filament::input.wrapper></label>
                <button type="button" class="workflow-headers-field__remove" aria-label="Удалить заголовок" x-on:click="removeRow(row.id)"><x-filament::icon icon="heroicon-o-trash" class="h-4 w-4"/></button>
            </div></template><button type="button" class="workflow-headers-field__add" x-on:click="addRow()">Добавить заголовок</button></div>
            <div x-show="mode === 'json'"><x-filament::input.wrapper><textarea class="fi-input" rows="4" x-model="raw" x-on:input="syncJson()" data-workflow-value-input aria-label="Заголовки JSON"></textarea></x-filament::input.wrapper></div>
            <small role="status" x-show="error" x-text="error"></small>
        </div>
    </template>
    <template data-local-inspector-template="code">
        <div class="fi-input-wrp fi-fo-code-editor workflow-javascript-editor" x-on:workflow-local-field-refresh="refreshField()"><div data-local-code x-on:keydown.ctrl.shift.f.prevent.stop="formatCode()" x-on:keydown.meta.shift.f.prevent.stop="formatCode()">
            <div class="workflow-javascript-editor__toolbar"><span>JavaScript</span><button type="button" x-on:click="formatCode()" x-bind:disabled="formatDisabled || formatting || !editor" title="Форматировать код (Ctrl/Cmd + Shift + F)" x-text="formatting ? 'Форматирование…' : 'Форматировать'">Форматировать</button></div>
            <p class="workflow-javascript-editor__error" role="status" x-show="formatError" x-text="formatError" x-cloak></p>
            <div x-ref="editor" x-cloak aria-label="Код JavaScript"></div>
        </div></div>
    </template>
    <template data-local-inspector-template="remove"><button type="button" class="fi-icon-btn fi-size-sm" aria-label="Удалить элемент"><x-filament::icon icon="heroicon-o-trash" class="h-4 w-4"/></button></template>
    <template data-local-inspector-template="reorder"><button type="button" class="fi-icon-btn fi-size-sm" aria-label="Изменить порядок" draggable="true"><x-filament::icon icon="heroicon-o-bars-2" class="h-4 w-4"/></button></template>
    <template data-local-inspector-template="collapse"><span class="fi-section-collapse-btn"><x-filament::icon icon="heroicon-o-chevron-down" class="h-4 w-4"/></span></template>
    <template data-local-inspector-template="input">@include('filament.workflow-builder.workflow-expression-picker',['sources'=>$inspectorBootstrap['sources'],'sourcesExpression'=>'JSON.parse(JSON.stringify(local.sources()))'])</template>
</div>
