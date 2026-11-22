const normalize = value => String(value ?? '').trim().toLocaleLowerCase();

const fieldLabels = {
    id: 'ID', entity_id: 'ID сущности', contact_id: 'ID контакта', lead_id: 'ID сделки', company_id: 'ID компании',
    customer_id: 'ID покупателя', task_id: 'ID задачи', name: 'Имя', first_name: 'Имя', last_name: 'Фамилия',
    phone: 'Телефон', email: 'Email', price: 'Бюджет', pipeline_id: 'ID воронки', status_id: 'ID этапа',
    responsible_user_id: 'ID ответственного', text: 'Текст', message: 'Сообщение', title: 'Название',
    entity: 'Тип сущности', entity_type: 'Тип сущности', author: 'Автор', tags: 'Теги', value: 'Значение',
    items: 'Элементы', body: 'Ответ', data: 'Данные', result: 'Результат', success: 'Успешно', status: 'Статус',
    created_at: 'Дата создания', updated_at: 'Дата изменения', city: 'Город', address: 'Адрес',
};
const entities = {
    lead: {id: 'ID сделки', name: 'Название сделки', list: 'Список сделок'},
    contact: {id: 'ID контакта', name: 'Имя контакта', list: 'Список контактов'},
    company: {id: 'ID компании', name: 'Название компании', list: 'Список компаний'},
    customer: {id: 'ID покупателя', name: 'Имя покупателя', list: 'Список покупателей'},
    task: {id: 'ID задачи', name: 'Название задачи', list: 'Список задач'},
};
const entityTypes = {lead:'lead',leads:'lead',contact:'contact',contacts:'contact',company:'company',companies:'company',customer:'customer',customers:'customer',task:'task',tasks:'task'};
const entityType = value => typeof value === 'string' && Object.hasOwn(entityTypes, value) ? entityTypes[value] : null;
const pathParts = field => String(field.key ?? field.path ?? field.label ?? '').replace(/^\./, '').split('.').filter(Boolean);

export function compactPickerEntity(type, config = {}) {
    if (type === 'amocrm_read') {
        const [entity, operation] = String(config.operation || '').split('.');
        return ['list','one'].includes(operation) ? entityType(entity) : null;
    }
    if (type === 'amocrm_find_entity') return entityType(config.target_entity);
    if (type === 'amocrm_contact_leads') return 'lead';
    const match = /^amocrm_(?:get|create|copy|update|query)_(leads?|contacts?|companies|company|customers?|tasks?)(?:_fields)?$/.exec(type || '');
    return match ? entityType(match[1]) : null;
}

function fieldEntity(source, field) {
    const parts = pathParts(field);
    const nested = parts.slice(0, -1).reverse().map(entityType).find(Boolean);
    if (field.projected) return nested || (parts.length <= 1 ? field.entity : null);
    return nested || (parts.length <= 1 ? entityType(source?.entity) : null);
}

function fieldLabel(source, field) {
    const parts = pathParts(field), key = parts.at(-1) || field.label;
    const entity = fieldEntity(source, field);
    const original = field.label ?? key;
    // Keep supplied business labels; only translate raw property names.
    if (original && !Object.hasOwn(fieldLabels, original) && original !== key) return original;
    if (entity && ['id','entity_id'].includes(key)) return entities[entity].id;
    if (entity && key === 'name') return entities[entity].name;
    return Object.hasOwn(fieldLabels, original) ? fieldLabels[original]
        : Object.hasOwn(fieldLabels, key) ? fieldLabels[key] : original || 'Значение';
}

function collectionLabel(source, field) {
    const key = pathParts(field).at(-1), entity = entityType(key) || (['items','data','results',undefined].includes(key) ? entityType(source?.entity) : null);
    if (entity) return entities[entity].list;
    return key && !['items','data','results'].includes(key) ? 'Список: ' + (Object.hasOwn(fieldLabels,key) ? fieldLabels[key] : field.label || key) : 'Список элементов';
}

function countLabel(count, forms) {
    const last = count % 100 >= 11 && count % 100 <= 14 ? 2 : count % 10 === 1 ? 0 : count % 10 >= 2 && count % 10 <= 4 ? 1 : 2;
    return count + ' ' + forms[last];
}

function fieldPreview(source, field) {
    if (source?.available === false || field.available === false) return '';
    if (field.type === 'array') return Number.isInteger(field.count) ? countLabel(field.count,['элемент','элемента','элементов']) : '';
    if (field.type === 'object') return Number.isInteger(field.count) ? countLabel(field.count,['поле','поля','полей']) : '';
    if (!Object.hasOwn(field, 'value')) return '';
    if (field.value === null) return 'Не заполнено';
    if (typeof field.value === 'boolean') return field.value ? 'Да' : 'Нет';
    if (typeof field.value === 'string') return field.value.replace(/\s+/g, ' ').trim().slice(0, 80) || 'Не заполнено';
    return typeof field.value === 'number' ? String(field.value) : '';
}

export function compactPickerFields(source, query = '', collectionOnly = false, {allowStructured = true, forMenu = false, target = null} = {}) {
    const terms = normalize(query).split(/\s+/).filter(Boolean);
    const seen = new Set();
    const candidates = (source?.fields ?? []).flatMap(field => {
        if (forMenu && !allowStructured && !pathParts(field).length && ['null','undefined'].includes(field.type)) return [];
        if (collectionOnly) return field.type === 'array' ? [{...field, label: collectionLabel(source, field), collection: true}] : [];
        if (field.type === 'object') {
            if (!allowStructured || (forMenu && field.count === 0)) return [];
            return [{...field, label: pathParts(field).length ? 'Данные: ' + fieldLabel(source, field) : 'Данные шага'}];
        }
        if (field.type !== 'array' || !field.expression) return [field];
        const base = field.expression.replace(/^\{\{\s*|\s*\}\}$/g, '');
        const paths = (field.item_fields ?? []).filter(path => /^[\p{L}_][\p{L}\p{N}_-]*(?:\.[\p{L}_][\p{L}\p{N}_-]*)*$/u.test(path));
        const key = pathParts(field).at(-1);
        const entity = entityType(key) || (['items','data','results',undefined].includes(key) ? entityType(source?.entity) : null);
        return [...(allowStructured ? [{...field, label: collectionLabel(source, field), collection: true}] : []), ...paths
            .filter(path => allowStructured || (!['object','array'].includes(field.item_examples?.[path]?.type) && !paths.some(other => other.startsWith(path + '.'))))
            .map(path => ({...field.item_examples?.[path], available:field.available, label: path.split('.').at(-1), key: path, projected: true, entity,
                detail: 'Первый в списке', expression: '{{ ' + base + '.first().' + path + ' }}'}))];
    });
    const fields = candidates.flatMap(field => {
        if (!field.expression || seen.has(field.expression)) return [];
        let choice;
        if (target?.choices) {
            const known = source?.available !== false && field.available !== false && Object.hasOwn(field,'value');
            choice = Object.keys(target.choices).find(key => key === String(field.value) || (entityType(key) && entityType(key) === entityType(field.value)));
            if ((known && choice === undefined) || (!known && ['number','int','float','boolean','bool','array','object'].includes(field.type))) return [];
        }
        seen.add(field.expression);
        const label = field.collection || (field.type === 'object' && !field.projected) ? field.label : fieldLabel(source, field);
        const path = field.key?.replace(/^\./, '') ?? field.path;
        const preview = choice === undefined ? fieldPreview(source, field) : target.choices[choice];
        const text = normalize([label, field.label, Object.hasOwn(fieldLabels,pathParts(field).at(-1)) ? fieldLabels[pathParts(field).at(-1)] : '', path, field.expression, source?.name, preview].join(' '));
        return terms.every(term => text.includes(term)) ? [{...field, label, preview, displayPath: path}] : [];
    });
    if (!target) return fields;
    const wanted = String(target.path || '').split('.').at(-1);
    const rank = item => {
        const key = pathParts(item).at(-1);
        if (key && key === wanted) return 0;
        if (wanted?.endsWith('_id') && ['id','entity_id'].includes(key)) return 1;
        return ['id','entity_id','name','first_name','phone','email','price'].includes(key) ? 2 : 3;
    };
    return fields.sort((a,b) => rank(a) - rank(b));
}

export function compactPickerGroups(sources, query = '', options = {}) {
    return [...(sources || [])].reverse().flatMap(source => {
        const fields = compactPickerFields(source, query, Boolean(options.collectionOnly), {...options, forMenu: true});
        return fields.length ? [{id:source.id, name:source.id === '$vars' ? 'Переменные' : source.name, entity:source.entity,
            available:source.available, fields, hasExamples:fields.some(field => field.preview !== '')}] : [];
    });
}

export function compactPickerPosition(panel, anchor, viewport, size) {
    const gap = 8;
    const width = Math.min(size.width, Math.max(0, viewport.width - gap * 2));
    const height = Math.min(size.height, Math.max(0, viewport.height - gap * 2));
    const clamp = (value, max) => Math.max(gap, Math.min(value, max - gap));
    const left = panel.left - width - gap;
    const right = panel.right + gap;
    if (left >= gap || right + width <= viewport.width - gap) {
        return {left: left >= gap ? left : right, top: clamp(anchor.top - 90, viewport.height - height)};
    }
    const below = anchor.bottom + gap;
    const above = anchor.top - height - gap;
    return {
        left: clamp(anchor.right - width, viewport.width - width),
        top: clamp(below + height <= viewport.height - gap ? below : above, viewport.height - height),
    };
}

export const workflowCompactFieldPicker = () => ({
    pickerOpen: false, pickerEditExpression: null, pickerSources: [], pickerQuery: '',
    pickerIndex: 0, pickerCollectionOnly: false, pickerAllowStructured: false, pickerTarget: {},
    pickerExpanded: [], pickerSearchClosed: [], pickerAllFields: [], pickerPosition: {left: '8px', top: '8px'},
    get pickerGroups() {
        let index = 0;
        return compactPickerGroups(this.pickerSources, this.pickerQuery, {collectionOnly:this.pickerCollectionOnly,allowStructured:this.pickerAllowStructured,target:this.pickerTarget})
            .map(group => {
                const expanded = this.pickerQuery.trim() ? !this.pickerSearchClosed.includes(group.id) : this.pickerExpanded.includes(group.id);
                const fields = expanded ? this.pickerAllFields.includes(group.id) ? group.fields : group.fields.slice(0, 6) : [];
                return {...group, expanded, count:group.fields.length, hasMore:fields.length < group.fields.length,
                    fields:fields.map(field => ({...field, index:index++}))};
            });
    },
    get pickerFields() { return this.pickerGroups.flatMap(group => group.fields); },
    get pickerEmptyHint() {
        const previous = this.pickerSources.filter(source => source.id !== 'trigger' && !String(source.id).startsWith('trigger:') && source.id !== '$vars');
        if (!previous.length) return 'Сначала добавьте шаг, который получает данные.';
        return previous.some(source => source.available) ? 'В результате нет подходящих полей. Проверьте предыдущий шаг.'
            : 'Протестируйте предыдущий шаг, чтобы увидеть его данные.';
    },
    togglePickerSource(id) {
        if (this.pickerQuery.trim()) this.pickerSearchClosed = this.pickerSearchClosed.includes(id) ? this.pickerSearchClosed.filter(value => value !== id) : [...this.pickerSearchClosed,id];
        else this.pickerExpanded = this.pickerExpanded.includes(id) ? [] : [id];
        this.pickerIndex = 0;
        this.$nextTick(() => this.positionCompactPicker());
    },
    showAllPickerFields(id) {
        this.pickerAllFields = [...this.pickerAllFields,id];
        this.$nextTick(() => this.positionCompactPicker());
    },
    resetPickerSearch() {
        this.pickerIndex = 0; this.pickerSearchClosed = []; this.pickerAllFields = [];
        this.$nextTick(() => this.positionCompactPicker());
    },
    openCompactPicker({anchor, sources, onSelect, onEditExpression, collectionOnly, allowStructured = false, target = {}}) {
        if (!anchor?.isConnected || typeof onSelect !== 'function') return;
        if (this.pickerOpen && this.pickerAnchor === anchor) {
            this.closeCompactPicker(true);
            return;
        }
        this.closeCompactPicker(false);
        this.pickerAnchor = anchor; this.pickerSelect = onSelect; this.pickerEditExpression = onEditExpression;
        this.pickerCollectionOnly = Boolean(collectionOnly); this.pickerAllowStructured = Boolean(allowStructured);
        this.pickerTarget = target; this.pickerSources = Array.isArray(sources) ? sources : [];
        this.pickerQuery = ''; this.pickerIndex = 0; this.pickerSearchClosed = []; this.pickerAllFields = [];
        const groups = compactPickerGroups(this.pickerSources, '', {collectionOnly:this.pickerCollectionOnly,allowStructured:this.pickerAllowStructured,target});
        const matching = target.entity ? groups.filter(group => group.entity === target.entity) : [];
        const preferred = matching.find(group => group.available) || matching[0] || groups.find(group => group.available) || groups[0];
        this.pickerExpanded = preferred ? [preferred.id] : [];
        const preferredSource = preferred && this.pickerSources.find(source => source.id === preferred.id);
        if (preferredSource) this.pickerSources = [...this.pickerSources.filter(source => source !== preferredSource), preferredSource];
        this.pickerOpen = true;
        anchor.setAttribute('aria-expanded', 'true');
        this.$nextTick(() => {
            if (!this.pickerOpen || this.pickerAnchor !== anchor) return;
            this.positionCompactPicker(); this.$refs.pickerSearch?.focus({preventScroll: true});
        });
        const onKey = event => {
            if (event.key !== 'Escape') return;
            event.preventDefault(); event.stopImmediatePropagation(); this.closeCompactPicker(true);
        };
        const onPointer = event => {
            if (this.pickerAnchor?.contains(event.target) || this.$refs.compactPicker?.contains(event.target)) return;
            this.closeCompactPicker(false);
        };
        const onMove = () => this.positionCompactPicker();
        window.addEventListener('keydown', onKey, true); document.addEventListener('pointerdown', onPointer, true);
        window.addEventListener('resize', onMove); document.addEventListener('scroll', onMove, true);
        this.pickerCleanup = () => {
            window.removeEventListener('keydown', onKey, true); document.removeEventListener('pointerdown', onPointer, true);
            window.removeEventListener('resize', onMove); document.removeEventListener('scroll', onMove, true);
        };
    },
    positionCompactPicker() {
        const popup = this.$refs.compactPicker;
        const anchor = this.pickerAnchor;
        if (!popup || !anchor?.isConnected || !anchor.getClientRects().length) {
            this.closeCompactPicker(false);
            return;
        }
        const triggerRect = anchor.getBoundingClientRect();
        const panel = anchor.closest('.workflow-inspector')?.getBoundingClientRect() ?? triggerRect;
        const viewport = {width: document.documentElement.clientWidth, height: document.documentElement.clientHeight};
        const position = compactPickerPosition(panel, triggerRect, viewport, {width: popup.offsetWidth, height: popup.offsetHeight});
        this.pickerPosition = {left: position.left + 'px', top: position.top + 'px'};
    },
    closeCompactPicker(restoreFocus = false) {
        this.pickerCleanup?.();
        this.pickerCleanup = null;
        this.pickerOpen = false;
        this.pickerAnchor?.setAttribute('aria-expanded', 'false');
        if (restoreFocus) this.pickerAnchor?.focus({preventScroll: true});
        this.pickerAnchor = null;
        this.pickerSelect = null;
        this.pickerEditExpression = null;
    },
    chooseCompactField(item) {
        if (!item?.expression) return;
        const select = this.pickerSelect;
        if (!this.pickerAnchor?.isConnected) {
            this.closeCompactPicker(false);
            return;
        }
        this.closeCompactPicker(true);
        select?.(item);
    },
    editCompactExpression() {
        const edit = this.pickerEditExpression;
        this.closeCompactPicker(true);
        edit?.();
    },
    compactPickerKey(event) {
        if (event.target.tagName === 'SELECT' || event.target.closest('[data-workflow-picker-close], [data-workflow-picker-edit], [data-workflow-picker-group], [data-workflow-picker-more]')) return;
        const count = this.pickerFields.length;
        if (!count) return;
        const row = event.target.closest('[data-workflow-picker-row]');
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            event.stopPropagation();
            const current = row ? Number(row.dataset.workflowPickerRow) : this.pickerIndex;
            this.pickerIndex = (current + (event.key === 'ArrowDown' ? 1 : -1) + count) % count;
            const target = this.$refs.compactPicker?.querySelector('[data-workflow-picker-row="' + this.pickerIndex + '"]');
            if (row) target?.focus();
            else target?.scrollIntoView({block: 'nearest'});
        } else if (event.key === 'Enter' && event.target === this.$refs.pickerSearch) {
            event.preventDefault();
            event.stopPropagation();
            this.chooseCompactField(this.pickerFields[this.pickerIndex]);
        }
    },
    destroy() { this.closeCompactPicker(false); },
});

if (typeof window !== 'undefined') window.workflowCompactFieldPicker = workflowCompactFieldPicker;
