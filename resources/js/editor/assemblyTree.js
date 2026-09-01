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

export const flattenAssemblyTree = (groups, parts, rootId = null, collapsedIds = []) => {
    const collapsed = new Set(collapsedIds);
    const sortedGroups = [...groups].sort((left, right) => left.sort_order - right.sort_order || left.name.localeCompare(right.name));
    const childrenByParent = new Map();
    const instancesByGroup = new Map();

    sortedGroups.forEach((group) => {
        const children = childrenByParent.get(group.parent_id) ?? [];
        children.push(group);
        childrenByParent.set(group.parent_id, children);
    });

    allInstances(parts).forEach((item) => {
        const groupInstances = instancesByGroup.get(item.instance.assembly_group_id) ?? [];
        groupInstances.push(item);
        instancesByGroup.set(item.instance.assembly_group_id, groupInstances);
    });

    const rows = [];
    const appendBranch = (parentId, depth) => {
        (childrenByParent.get(parentId) ?? []).forEach((group) => {
            rows.push({ kind: 'group', id: `group-${group.id}`, depth, group });

            if (collapsed.has(group.id)) return;

            (instancesByGroup.get(group.id) ?? []).forEach(({ instance, part }) => {
                rows.push({ kind: 'instance', id: `instance-${instance.id}`, depth: depth + 1, instance, part });
            });
            appendBranch(group.id, depth + 1);
        });
    };

    (instancesByGroup.get(rootId) ?? []).forEach(({ instance, part }) => {
        rows.push({ kind: 'instance', id: `instance-${instance.id}`, depth: 0, instance, part });
    });
    appendBranch(rootId, 0);

    return rows;
};
