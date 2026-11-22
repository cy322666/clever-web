const types = {lead:'lead',leads:'lead',contact:'contact',contacts:'contact',company:'company',companies:'company',customer:'customer',customers:'customer'};

export function linkEntityType(value) {
    if (typeof value !== 'string') return null;
    const key = value.trim().toLowerCase();
    return Object.hasOwn(types, key) ? types[key] : null;
}

export function linkedEntityOptions(source, options) {
    const type = linkEntityType(source);
    return Object.fromEntries(Object.entries(options).filter(([key]) => !type || linkEntityType(key) !== type));
}

// Called only after the user changes the source type, never merely on opening a form.
export function clearConflictingLink(config) {
    const source = linkEntityType(config.target_entity);
    if (source && source === linkEntityType(config.linked_entity)) {
        config.linked_entity = null;
        config.linked_entity_id = null;
    }
}
