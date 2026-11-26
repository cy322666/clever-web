import {clone} from './workflow-browser-document.mjs';

const branches = ['true_actions', 'false_actions'];
const edgeKey = edge => JSON.stringify([edge.sourceId, edge.sourcePort, edge.targetId]);

function actionSlots(actions, parent = null, branch = null, slots = new Map()) {
    actions.forEach((node, index) => {
        slots.set(String(node.id), {node, parent, branch, index, list: actions});
        for (const key of branches) actionSlots(node.config?.[key] || [], String(node.id), key, slots);
    });
    return slots;
}

function rekeyEdge(edge, from, to) {
    return edge.sourceId === from ? {...edge, sourceId: to} : edge;
}

function restoreItems(current, removed, added, key) {
    const addedKeys = new Set(added.map(key));
    const result = current.filter(item => !addedKeys.has(key(item)));
    for (const {item, index} of removed) {
        if (!result.some(existing => key(existing) === key(item))) result.splice(index, 0, item);
    }
    return result;
}

function changes(before, after, key) {
    const previous = new Set(before.map(key));
    const next = new Set(after.map(key));
    return {
        removed: before.flatMap((item, index) => next.has(key(item)) ? [] : [{item, index}]),
        added: after.filter(item => !previous.has(key(item))),
    };
}

// Capture only the parts of the draft changed by creating or removing nodes.
// Later settings, routes and connection edits on other nodes survive undo.
export function nodeUndoEntry(before, after, beforeEdges, afterEdges, points, promoted = null) {
    const oldSlots = actionSlots(before.actions);
    const newSlots = actionSlots(after.actions);
    const addedActions = [...newSlots.keys()].filter(id => !oldSlots.has(id));
    const restoredActions = [];
    const loopFlags = [];
    for (const [id, {node, parent, branch, index}] of oldSlots) {
        const next = newSlots.get(id);
        if (!next || next.parent !== parent || next.branch !== branch) {
            restoredActions.push({id, node: next ? null : clone(node), parent, branch, index});
        }
        if (next && node.config?.require_return !== next.node.config?.require_return) {
            loopFlags.push({id, previous: node.config?.require_return, next: next.node.config?.require_return});
        }
    }
    const oldStarts = before.additional_triggers || [];
    const newStarts = after.additional_triggers || [];
    const starts = changes(oldStarts, newStarts, node => node.id);
    const primaryChanged = Boolean(promoted) || Boolean(before.trigger) !== Boolean(after.trigger);
    if (!addedActions.length && !restoredActions.length && !starts.added.length && !starts.removed.length && !primaryChanged) return null;

    const edges = changes(beforeEdges, promoted ? afterEdges.map(edge => rekeyEdge(edge, 'trigger', promoted)) : afterEdges, edgeKey);
    const routes = {};
    for (const {item} of edges.removed) {
        const key = edgeKey(item);
        if (before.canvas_edge_routes?.[key]) routes[key] = before.canvas_edge_routes[key];
    }
    const coordinates = {};
    for (const slot of restoredActions) {
        if (slot.node && points['action:' + slot.id]) coordinates['action:' + slot.id] = points['action:' + slot.id];
    }
    for (const {item} of starts.removed) if (points[item.id]) coordinates[item.id] = points[item.id];
    if (primaryChanged && points.trigger) coordinates.trigger = points.trigger;
    // An insertion may shift existing nodes when the new card is positioned.
    return {
        addedActions, restoredActions, loopFlags, starts, promoted, primaryChanged, primary: before.trigger ?? null,
        edges, routes, coordinates, legacy: !Array.isArray(before.connections),
        created: new Set([
            ...addedActions.map(id => 'action:' + id), ...starts.added.map(node => node.id),
            ...(!before.trigger && after.trigger ? ['trigger'] : []),
        ]),
    };
}

export function restoreNodeEdit(entry, current, edges, points, routes, legacyEdges) {
    const liveSlots = actionSlots(current.actions);
    const moving = [...entry.addedActions, ...entry.restoredActions.map(slot => slot.id)];
    for (const id of moving) {
        const slot = liveSlots.get(id);
        if (slot) slot.list.splice(slot.list.indexOf(slot.node), 1);
    }
    for (const slot of entry.restoredActions) {
        const node = liveSlots.get(slot.id)?.node ?? clone(slot.node);
        if (!node) continue;
        if (slot.node) {
            // Children detached by deletion are reinserted from their live state below.
            for (const branch of branches) if (node.config?.[branch]) node.config[branch] = [];
        }
        let list = current.actions;
        if (slot.parent) {
            const parent = actionSlots(current.actions).get(slot.parent)?.node;
            if (!parent) continue;
            list = parent.config[slot.branch] ??= [];
        }
        list.splice(slot.index, 0, node);
    }
    for (const {id, previous, next} of entry.loopFlags) {
        const config = liveSlots.get(id)?.node.config;
        if (config?.require_return !== next || !config) continue;
        if (previous === undefined) delete config.require_return;
        else config.require_return = previous;
    }
    const promoted = entry.promoted && current.trigger ? {...current.trigger, id: entry.promoted} : null;
    const removedStarts = entry.starts.removed.map(({item, index}) => ({item: promoted?.id === item.id ? promoted : item, index}));
    if (removedStarts.length || entry.starts.added.length) {
        current.additional_triggers = restoreItems(current.additional_triggers || [], removedStarts, entry.starts.added, node => node.id);
    }
    if (entry.primaryChanged) current.trigger = entry.primary;

    const remaining = new Set([
        ...(current.trigger ? ['trigger'] : []), ...(current.additional_triggers || []).map(node => node.id),
        ...[...actionSlots(current.actions).keys()].map(id => 'action:' + id),
    ]);
    const restored = restoreItems(
        entry.promoted ? edges.map(edge => rekeyEdge(edge, 'trigger', entry.promoted)) : edges,
        entry.edges.removed, entry.edges.added, edgeKey,
    ).filter(edge => remaining.has(edge.sourceId) && remaining.has(edge.targetId));
    if (entry.legacy && restored.map(edgeKey).join('\n') === legacyEdges.map(edgeKey).join('\n')) {
        delete current.connections;
    } else {
        current.connections = restored;
    }

    const geometry = {...points, ...entry.coordinates};
    if (entry.promoted && points.trigger) geometry[entry.promoted] = points.trigger;
    current.canvas_positions = Object.fromEntries(Object.entries(geometry).filter(([id]) => remaining.has(id)));
    if (!current.trigger && entry.primaryChanged && geometry.trigger) current.canvas_positions.trigger = geometry.trigger;
    const routeMap = {...routes};
    if (entry.promoted) {
        for (const edge of edges.filter(edge => edge.sourceId === 'trigger')) {
            const key = edgeKey(edge);
            if (routeMap[key]) routeMap[edgeKey(rekeyEdge(edge, 'trigger', entry.promoted))] = routeMap[key];
            delete routeMap[key];
        }
    }
    const validRoutes = new Set(restored.map(edgeKey));
    current.canvas_edge_routes = Object.fromEntries(Object.entries({...entry.routes, ...routeMap}).filter(([key]) => validRoutes.has(key)));
    return remaining;
}
