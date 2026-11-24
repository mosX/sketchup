import { assemblyBreadcrumbs } from './assemblyTree.js';
import { fastenerFitsFace, fastenerStockBounds, inspectFastenerReach } from './fasteners.js';
import { surfaceDimensions } from './operations/shared.js';

export const analyzeFasteners = (fasteners, parts, groups, materialFor = null) => {
    const instances = new Map(parts.flatMap(part => (part.instances ?? []).map(instance => [instance.id, { part, instance }])));
    const bounds = new Map();
    const paths = new Map();
    const issues = new Map();
    let unchecked = 0;
    let issueCount = 0;
    let pending = false;
    let approximate = false;

    const instanceBounds = (item) => {
        const id = item.instance.id;
        if (!bounds.has(id)) bounds.set(id, fastenerStockBounds(item));
        return bounds.get(id);
    };

    for (const fastener of fasteners) {
        const source = instances.get(fastener.source_instance_id);
        const target = instances.get(fastener.target_instance_id);
        if (!source) {
            unchecked++;
            continue;
        }

        const surface = surfaceDimensions(fastener.face, source.part.dimensions);
        let status = 'outside';
        let distance = null;
        if (fastenerFitsFace(fastener, surface)) {
            if (!target && !materialFor) {
                unchecked++;
                continue;
            }
            const reach = inspectFastenerReach(fastener, instanceBounds(source), target ? [instanceBounds(target)] : [], materialFor);
            pending ||= reach.pending;
            approximate ||= reach.approximate;
            status = reach.status;
            if (!target && status !== 'unsupported') {
                unchecked++;
                continue;
            }
            if (status === 'reaches') continue;
            if (status === 'short') distance = reach.shortage;
            if (status === 'through') distance = reach.protrusion;
        }

        const targetId = target?.instance.id ?? null;
        const type = fastener.fastener_type ?? 'screw';
        const key = [source.instance.id, fastener.face, targetId, type, status].join(':');
        if (!issues.has(key)) {
            const groupId = source.instance.assembly_group_id;
            if (!paths.has(groupId)) paths.set(groupId, assemblyBreadcrumbs(groups, groupId).map(group => group.name).join(' / '));
            issues.set(key, {
                key, status, type, face: fastener.face, group: paths.get(groupId),
                sourceName: source.part.name, targetName: target?.part.name ?? null,
                minimum: distance, maximum: distance,
                context: {
                    instance_ids: targetId === null ? [source.instance.id] : [source.instance.id, targetId],
                    fastener_ids: [], fastener_status: status,
                },
            });
        }
        const issue = issues.get(key);
        issue.context.fastener_ids.push(fastener.id);
        if (distance !== null) {
            issue.minimum = Math.min(issue.minimum, distance);
            issue.maximum = Math.max(issue.maximum, distance);
        }
        issueCount++;
    }

    return {
        total: fasteners.length, unchecked, issueCount, pending, approximate,
        issues: [...issues.values()].sort((a, b) => a.group.localeCompare(b.group, 'ru') || a.sourceName.localeCompare(b.sourceName, 'ru')),
    };
};
