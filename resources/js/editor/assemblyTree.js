const allInstances = (parts) => parts.flatMap((part) => (part.instances ?? []).map((instance) => ({ instance, part })));

export const descendantGroupIds = (groups, rootId, includeRoot = true) => {
    const childrenByParent = new Map();

    groups.forEach((group) => {
        const children = childrenByParent.get(group.parent_id) ?? [];
        children.push(group.id);
        childrenByParent.set(group.parent_id, children);
    });

    const ids = includeRoot ? [rootId] : [];
    const pending = [...(childrenByParent.get(rootId) ?? [])];

    while (pending.length) {
        const groupId = pending.shift();
        ids.push(groupId);
        pending.push(...(childrenByParent.get(groupId) ?? []));
    }

    return ids;
};

export const assemblyBreadcrumbs = (groups, rootId) => {
    const byId = new Map(groups.map((group) => [group.id, group]));
    const breadcrumbs = [];
    let group = byId.get(rootId);

    while (group) {
        breadcrumbs.unshift(group);
        group = byId.get(group.parent_id);
    }

    return breadcrumbs;
};

const inheritedState = (groupsById, groupId, property, fallback, scopeRootId = null) => {
    let group = groupsById.get(groupId);
    let value = fallback;

    while (group) {
        const ignoreOpenedRootVisibility = property === 'is_visible' && group.id === scopeRootId;

        if (!ignoreOpenedRootVisibility) {
            value = property === 'is_visible' ? value && group[property] !== false : value || group[property] === true;
        }

        if (group.id === scopeRootId) break;

        group = groupsById.get(group.parent_id);
    }

    return value;
};

export const assemblyInstanceState = (groups, parts, activeRootId = null, isolatedGroupId = null) => {
    const groupsById = new Map(groups.map((group) => [group.id, group]));
    const scopeRootId = isolatedGroupId ?? activeRootId;
    const scopedGroupIds = scopeRootId === null ? null : new Set(descendantGroupIds(groups, scopeRootId));
    const visibleInstanceIds = [];
    const lockedInstanceIds = [];

    allInstances(parts).forEach(({ instance }) => {
        const groupId = instance.assembly_group_id;
        const isInScope = scopedGroupIds === null ? true : groupId !== null && scopedGroupIds.has(groupId);
        const isVisible = groupId === null ? true : inheritedState(groupsById, groupId, 'is_visible', true, scopeRootId);
        const isLocked = groupId === null ? false : inheritedState(groupsById, groupId, 'is_locked', false, scopeRootId);

        if (isInScope && isVisible) visibleInstanceIds.push(instance.id);
        if (isLocked) lockedInstanceIds.push(instance.id);
    });

    return { visibleInstanceIds, lockedInstanceIds };
};

export const assemblyLevelRows = (groups, parts, parentId = null) => [
    ...groups.filter(group => group.parent_id === parentId)
        .sort((left, right) => left.sort_order - right.sort_order || left.name.localeCompare(right.name))
        .map(group => ({ kind: 'group', id: `group-${group.id}`, group })),
    ...allInstances(parts).filter(({ instance }) => instance.assembly_group_id === parentId)
        .map(({ instance, part }) => ({ kind: 'instance', id: `instance-${instance.id}`, instance, part })),
];

export const resolveAssemblyPick = (groups, instance, activeGroupId = null) => {
    if (!instance) return null;

    const path = assemblyBreadcrumbs(groups, instance.assembly_group_id);
    const level = activeGroupId === null ? 0 : path.findIndex(group => group.id === activeGroupId) + 1;
    if (activeGroupId !== null && level === 0) return null;

    return path[level]
        ? { kind: 'group', group: path[level] }
        : { kind: 'instance', id: instance.id };
};
