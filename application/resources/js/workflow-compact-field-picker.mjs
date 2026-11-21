const normalize = value => String(value ?? '').trim().toLocaleLowerCase();

const fieldLabels = {
    id: 'ID', name: 'Имя', first_name: 'Имя', last_name: 'Фамилия', phone: 'Телефон',
    email: 'Email', price: 'Бюджет', pipeline_id: 'ID воронки', status_id: 'ID этапа',
    responsible_user_id: 'ID ответственного',
};

export function compactPickerFields(source, query = '', collectionOnly = false) {
    const terms = normalize(query).split(/\s+/).filter(Boolean);
    const seen = new Set();
    const candidates = (source?.fields ?? []).flatMap(field => {
        if (collectionOnly) return field.type === 'array' ? [{...field, label: 'Весь список'}] : [];
        if (field.type !== 'array' || !field.expression) return [field];
        const base = field.expression.replace(/^\{\{\s*|\s*\}\}$/g, '');
        return [{...field, label: 'Весь список'}, ...(field.item_fields ?? [])
            .filter(path => /^[\p{L}_][\p{L}\p{N}_-]*(?:\.[\p{L}_][\p{L}\p{N}_-]*)*$/u.test(path))
            .map(path => ({
                label: (Object.hasOwn(fieldLabels, path) ? fieldLabels[path] : path) + ' · первый элемент',
                key: path, expression: '{{ ' + base + '.first().' + path + ' }}',
            }))];
    });
    return candidates.flatMap(field => {
        if (!field.expression || seen.has(field.expression)) return [];
        seen.add(field.expression);
        const label = (Object.hasOwn(fieldLabels, field.label) ? fieldLabels[field.label] : field.label) ?? field.path;
        const path = field.key?.replace(/^\./, '') ?? field.path;
        const text = normalize([label, field.label, path, field.expression].join(' '));
        return terms.every(term => text.includes(term)) ? [{...field, label, displayPath: path}] : [];
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
    pickerOpen: false, pickerSources: [], pickerSourceId: '', pickerQuery: '',
    pickerIndex: 0, pickerCollectionOnly: false, pickerPosition: {left: '8px', top: '8px'},
    get pickerFields() {
        return compactPickerFields(this.pickerSources.find(source => source.id === this.pickerSourceId), this.pickerQuery, this.pickerCollectionOnly);
    },
    openCompactPicker({anchor, sources, onSelect, onEditExpression, collectionOnly}) {
        if (!anchor?.isConnected || typeof onSelect !== 'function') return;
        if (this.pickerOpen && this.pickerAnchor === anchor) {
            this.closeCompactPicker(true);
            return;
        }
        this.closeCompactPicker(false);
        this.pickerAnchor = anchor;
        this.pickerSelect = onSelect;
        this.pickerEditExpression = onEditExpression;
        this.pickerCollectionOnly = Boolean(collectionOnly);
        this.pickerSources = Array.isArray(sources) ? sources : [];
        const upstream = this.pickerSources.filter(source => compactPickerFields(source, '', this.pickerCollectionOnly).some(field => field.key || field.type === 'array'));
        this.pickerSourceId = (upstream.findLast(source => source.available) ?? upstream.at(-1) ?? this.pickerSources.at(-1))?.id ?? '';
        this.pickerQuery = '';
        this.pickerIndex = 0;
        this.pickerOpen = true;
        anchor.setAttribute('aria-expanded', 'true');
        this.$nextTick(() => {
            if (!this.pickerOpen || this.pickerAnchor !== anchor) return;
            this.positionCompactPicker();
            this.$refs.pickerSearch?.focus({preventScroll: true});
        });
        const onKey = event => {
            if (event.key !== 'Escape') return;
            event.preventDefault();
            event.stopImmediatePropagation();
            this.closeCompactPicker(true);
        };
        const onPointer = event => {
            if (this.pickerAnchor?.contains(event.target) || this.$refs.compactPicker?.contains(event.target)) return;
            this.closeCompactPicker(false);
        };
        const onMove = () => this.positionCompactPicker();
        window.addEventListener('keydown', onKey, true);
        document.addEventListener('pointerdown', onPointer, true);
        window.addEventListener('resize', onMove);
        document.addEventListener('scroll', onMove, true);
        this.pickerCleanup = () => {
            window.removeEventListener('keydown', onKey, true);
            document.removeEventListener('pointerdown', onPointer, true);
            window.removeEventListener('resize', onMove);
            document.removeEventListener('scroll', onMove, true);
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
        if (event.target.tagName === 'SELECT' || event.target.closest('[data-workflow-picker-close], [data-workflow-picker-edit]')) return;
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
