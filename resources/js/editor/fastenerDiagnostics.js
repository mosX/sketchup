import { assemblyBreadcrumbs } from './assemblyTree.js';
import { fastenerFitsFace, fastenerStockBounds, inspectFastenerReach } from './fasteners.js';
import { surfaceDimensions } from './operations/shared.js';

export const analyzeFasteners = (fasteners, parts, groups, materialFor = null, { detailed = false, deadline = Infinity } = {}) => {
    const instances = new Map(parts.flatMap(part => (part.instances ?? []).map(instance => [instance.id, { part, instance }])));
    const bounds = new Map();
    const paths = new Map();
    const issues = new Map();
    let unchecked = 0;
    let issueCount = 0;
    let pending = false;
    let approximate = false;
    let geometryUnchecked = 0;
    let timeLimitReached = false;
    let assemblyBounds;

    const instanceBounds = (item) => {
        const id = item.instance.id;
        if (!bounds.has(id)) bounds.set(id, fastenerStockBounds(item));
        return bounds.get(id);
    };

    for (const [index, fastener] of fasteners.entries()) {
        if (performance.now() >= deadline) {
            timeLimitReached = true;
            unchecked += fasteners.length - index;
            break;
        }
        const source = instances.get(fastener.source_instance_id);
        const target = instances.get(fastener.target_instance_id);
        if (!source) {
            unchecked++;
            continue;
        }

        const surface = surfaceDimensions(fastener.face, source.part.dimensions);
        let status = 'outside';
        let distance = null;
        let inspectedTarget = target;
        if (fastenerFitsFace(fastener, surface)) {
            if (!target && !materialFor) {
                unchecked++;
                continue;
            }
            let candidates = target ? [instanceBounds(target)] : [];
            if (!target && detailed) candidates = assemblyBounds ??= [...instances.values()].map(instanceBounds);
            const reach = inspectFastenerReach(fastener, instanceBounds(source), candidates, materialFor, { checkExposedTip: detailed && !target });
            pending ||= reach.pending;
            approximate ||= reach.approximate;
            if (detailed && (reach.approximate || reach.pending)) {
                unchecked++;
                geometryUnchecked++;
                continue;
            }
            status = reach.status;
            if (!target && status !== 'unsupported' && !(detailed && ['reaches', 'through'].includes(status))) {
                unchecked++;
                continue;
            }
            if (detailed && !target) inspectedTarget = instances.get(reach.targetId);
            if (status === 'reaches') continue;
            if (status === 'short') distance = reach.shortage;
            if (status === 'through') distance = reach.protrusion;
        }

        const targetId = inspectedTarget?.instance.id ?? null;
        const type = fastener.fastener_type ?? 'screw';
        const key = [source.instance.id, fastener.face, targetId, type, status].join(':');
        if (!issues.has(key)) {
            const groupId = source.instance.assembly_group_id;
            if (!paths.has(groupId)) paths.set(groupId, assemblyBreadcrumbs(groups, groupId).map(group => group.name).join(' / '));
            issues.set(key, {
                key, status, type, face: fastener.face, group: paths.get(groupId),
                sourceName: source.part.name, targetName: inspectedTarget?.part.name ?? null,
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
        ...(detailed ? { geometryUnchecked, timeLimitReached } : {}),
        issues: [...issues.values()].sort((a, b) => a.group.localeCompare(b.group, 'ru') || a.sourceName.localeCompare(b.sourceName, 'ru')),
    };
};
