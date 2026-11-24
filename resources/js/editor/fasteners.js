import { ConeGeometry, CylinderGeometry, DoubleSide, Group, Mesh, MeshBasicMaterial, MeshStandardMaterial, RingGeometry, Vector3 } from 'three';
import { grooveFaceFrame, millimeterScale, partSize, setPartEditingFrame } from './geometry/coordinates.js';
import { mergeMaterialIntervals } from './geometry/fastenerMaterial.js';

export const fastenerAnchor = (part, fastener) => {
    const frame = grooveFaceFrame(part, fastener.face);
    return {
        position: frame.center.clone()
            .addScaledVector(frame.u, Number(fastener.center_u) * millimeterScale - frame.uSize / 2)
            .addScaledVector(frame.v, Number(fastener.center_v) * millimeterScale - frame.vSize / 2)
            .addScaledVector(frame.inward, Number(fastener.sink) * millimeterScale),
        direction: frame.inward,
        maximumDepth: frame.maximumDepth,
    };
};

export const fastenerHead = (diameter, type = 'screw') => type === 'nail' ? ({
    height: Number(diameter) * 0.22,
    baseRadius: Number(diameter) * 0.95,
    rimRadius: Number(diameter) * 0.95,
}) : ({
    height: Number(diameter) * 0.375,
    baseRadius: Number(diameter) * 0.7,
    rimRadius: Number(diameter) * 0.925,
});

export const countersinkHeadOffset = (fastener, settings) => {
    const { height, baseRadius, rimRadius } = fastenerHead(fastener.diameter);
    const bore = Number(settings.clearance_diameter) / 2;
    const opening = Number(settings.countersink_diameter) / 2;
    const depth = Number(settings.countersink_depth);
    if (bore >= rimRadius) return null;
    if (opening <= baseRadius) return height;

    const headTaper = (rimRadius - baseRadius) / height;
    const holeTaper = (opening - bore) / depth;
    const baseLimit = baseRadius > bore ? (opening - baseRadius) / holeTaper : Infinity;
    const rimLimit = opening < rimRadius ? (opening - baseRadius) / headTaper : height + (opening - rimRadius) / holeTaper;
    const shoulderLimit = depth + Math.max(0, bore - baseRadius) / headTaper;
    return height - Math.min(baseLimit, rimLimit, shoulderLimit);
};

const warningColors = { short: '#e34845', misses: '#e34845', outside: '#e34845', unsupported: '#e34845', through: '#e88524' };

export const createFastenerMesh = (part, fastener, preview = false, selected = false, diagnosticStatus = null) => {
    const radius = Number(fastener.diameter) * millimeterScale / 2;
    const length = Number(fastener.length) * millimeterScale;
    const tipLength = Math.min(fastener.fastener_type === 'nail' ? radius * 1.5 : radius, length / 4);
    const headShape = fastenerHead(fastener.diameter, fastener.fastener_type);
    const headHeight = headShape.height * millimeterScale;
    const anchor = fastenerAnchor(part, fastener);
    const warning = warningColors[diagnosticStatus ?? (preview && !fastener.reach?.pending ? fastener.reach?.status : null)];
    const material = new MeshStandardMaterial({
        color: warning ?? (preview && fastener.reach ? '#64748b' : preview || selected ? '#f5a623' : '#414952'),
        emissive: warning ?? '#000000',
        emissiveIntensity: warning ? 0.55 : 0,
        metalness: 0.55,
        roughness: 0.36,
        transparent: preview,
        opacity: preview && !warning ? 0.65 : 1,
    });
    const group = new Group();
    group.position.copy(anchor.position);
    group.quaternion.setFromUnitVectors(new Vector3(0, 1, 0), anchor.direction);

    const head = new Mesh(new CylinderGeometry(headShape.baseRadius * millimeterScale, headShape.rimRadius * millimeterScale, headHeight, 14), material);
    const shaft = new Mesh(new CylinderGeometry(radius, radius, length - tipLength, 10), material);
    const tip = new Mesh(new ConeGeometry(radius, tipLength, 10), material);
    head.position.y = -headHeight / 2;
    shaft.position.y = (length - tipLength) / 2;
    tip.position.y = length - tipLength / 2;
    for (const mesh of [head, shaft, tip]) {
        mesh.userData.fastenerId = fastener.id ?? null;
        group.add(mesh);
    }
    if (diagnosticStatus && warning) {
        const outerRadius = Math.max(headShape.rimRadius * millimeterScale * 1.7, 0.055);
        const ring = new Mesh(
            new RingGeometry(outerRadius * 0.74, outerRadius, 32),
            new MeshBasicMaterial({ color: warning, depthTest: false, depthWrite: false, side: DoubleSide }),
        );
        ring.rotation.x = Math.PI / 2;
        ring.position.y = -headHeight - 0.002;
        ring.renderOrder = 10;
        ring.userData.fastenerId = fastener.id;
        group.add(ring);
    }
    return group;
};

export const fastenerFitsFace = (fastener, surface) => {
    const radius = Number(fastener.diameter) / 2;
    const { center_u: u, center_v: v, sink } = fastener;
    return radius > 0 && sink >= 0 && sink <= surface.depth
        && u >= radius && u <= surface.u - radius && v >= radius && v <= surface.v - radius;
};

const tolerance = 1e-7;

export const fastenerStockBounds = ({ part, instance }) => {
    const pose = new Group();
    setPartEditingFrame(pose, part, instance);
    const { length, width, thickness } = partSize(part);
    return {
        part, instance,
        world: pose.matrixWorld,
        inverse: pose.matrixWorld.clone().invert(),
        half: new Vector3(length, thickness, width).multiplyScalar(0.5),
    };
};

const stockInterval = (origin, direction, target, margin = 0) => {
    const point = origin.clone().applyMatrix4(target.inverse);
    const axis = direction.clone().transformDirection(target.inverse);
    let entry = 0;
    let exit = Infinity;
    for (const key of ['x', 'y', 'z']) {
        const half = target.half[key] + margin;
        if (Math.abs(axis[key]) <= tolerance) {
            if (Math.abs(point[key]) > half + tolerance) return null;
            continue;
        }
        const first = (-half - point[key]) / axis[key];
        const last = (half - point[key]) / axis[key];
        entry = Math.max(entry, Math.min(first, last));
        exit = Math.min(exit, Math.max(first, last));
        if (exit <= entry + tolerance) return null;
    }
    return { entry, exit };
};

const probeRing = (probe, origin, direction, stock, radius) => {
    const point = origin.clone().applyMatrix4(stock.inverse);
    const axis = direction.clone().transformDirection(stock.inverse);
    const u = new Vector3(Math.abs(axis.x) < 0.8 ? 1 : 0, Math.abs(axis.x) < 0.8 ? 0 : 1, 0).cross(axis).normalize();
    const v = new Vector3().crossVectors(axis, u);
    const intervals = probe(point, axis);
    for (let index = 0; index < 8; index++) {
        const angle = index * Math.PI / 4;
        const offset = point.clone().addScaledVector(u, Math.cos(angle) * radius).addScaledVector(v, Math.sin(angle) * radius);
        intervals.push(...probe(offset, axis));
    }

    return mergeMaterialIntervals(intervals.filter(interval => interval.exit > tolerance)
        .map(interval => ({ entry: Math.max(interval.entry, 0), exit: interval.exit })));
};

export const inspectFastenerReach = (fastener, source, targets, materialFor = null) => {
    const anchor = fastenerAnchor(source.part, fastener);
    const sink = Number(fastener.sink) * millimeterScale;
    const direction = anchor.direction.transformDirection(source.world);
    const seatingPoint = anchor.position.applyMatrix4(source.world);
    let insideSource = Math.max(0, anchor.maximumDepth - sink);
    let pending = false;
    let approximate = false;
    const material = part => {
        const result = materialFor?.(part);
        pending ||= result?.state === 'pending';
        approximate ||= result?.state === 'error';
        return result?.probe;
    };
    const sourceProbe = material(source.part);
    if (sourceProbe) {
        const surface = seatingPoint.clone().addScaledVector(direction, -sink);
        const headRadius = fastenerHead(fastener.diameter, fastener.fastener_type).rimRadius * millimeterScale;
        const wood = probeRing(sourceProbe, surface, direction, source, headRadius * 0.98);
        if (!wood.length || wood.at(-1).exit < sink - tolerance) {
            return { status: 'unsupported', targetId: null, depth: 0, protrusion: 0, shortage: 0, pending, approximate };
        }
        insideSource = Math.max(0, wood.at(-1).exit - sink);
    }

    const origin = seatingPoint.addScaledVector(direction, insideSource);
    const remaining = Number(fastener.length) * millimeterScale - insideSource;
    const radius = Number(fastener.diameter ?? 0) / 2 * millimeterScale;
    const candidates = [];
    let nearest = null;
    for (const target of targets) {
        if (target === source || (source.instance?.id != null && source.instance.id === target.instance?.id)) continue;
        const bounds = stockInterval(origin, direction, target, materialFor ? radius : 0);
        if (bounds) candidates.push({ target, bounds });
    }
    candidates.sort((a, b) => a.bounds.entry - b.bounds.entry || a.target.instance.id - b.target.instance.id);
    let confirmedEntry = Infinity;
    for (const { target, bounds } of candidates) {
        if (bounds.entry > confirmedEntry + tolerance) break;
        const probe = material(target.part);
        const fallback = probe ? null : stockInterval(origin, direction, target);
        const intervals = probe ? probeRing(probe, origin, direction, target, radius * 0.98) : fallback ? [fallback] : [];
        if (!intervals.length) continue;
        const entry = intervals[0].entry;
        if (probe || !target.part.operations?.some(operation => operation.status !== 'draft' && operation.enabled !== false)) {
            confirmedEntry = Math.min(confirmedEntry, entry);
        }
        if (nearest === null || entry < nearest.entry - tolerance
            || (Math.abs(entry - nearest.entry) <= tolerance && target.instance.id < nearest.targetId)) {
            nearest = { intervals, entry, exit: intervals.at(-1).exit, targetId: target.instance.id };
        }
    }

    if (!nearest) return { status: 'misses', targetId: null, depth: 0, protrusion: 0, shortage: 0, pending, approximate };
    const depth = nearest.intervals.reduce((sum, interval) => sum + Math.max(0, Math.min(remaining, interval.exit) - interval.entry), 0);
    const protrusion = Math.max(0, remaining - nearest.exit);
    return {
        status: depth <= tolerance ? 'short' : protrusion > tolerance ? 'through' : 'reaches',
        targetId: nearest.targetId,
        depth: depth / millimeterScale,
        protrusion: protrusion / millimeterScale,
        shortage: Math.max(0, nearest.entry - remaining) / millimeterScale,
        pending, approximate,
    };
};

export const fastenerRow = (placement, count, axis, spacing) => Array.from({ length: count }, (_, index) => ({
    ...placement,
    center_u: Math.round((Number(placement.center_u) + (axis === 'u' ? index * spacing : 0)) * 100) / 100,
    center_v: Math.round((Number(placement.center_v) + (axis === 'v' ? index * spacing : 0)) * 100) / 100,
}));
