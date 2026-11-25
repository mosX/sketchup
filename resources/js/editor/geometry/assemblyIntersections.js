import { Box3, Matrix3, Matrix4, Vector3 } from 'three';
import { OBB } from 'three/addons/math/OBB.js';
import { Brush, Evaluator, INTERSECTION, computeMeshVolume } from 'three-bvh-csg';
import { millimeterScale } from './coordinates.js';
import { fastenerStockBounds } from '../fasteners.js';

const minimumPenetration = 0.01 * millimeterScale;
const minimumVolumeMm3 = 0.01;
const maxReportedIssues = 200;
const reflection = new Matrix4().makeScale(-1, 1, 1);

const stockBox = (stock) => {
    const local = new Box3(stock.half.clone().negate(), stock.half.clone());
    const orientation = new Matrix3().setFromMatrix4(stock.world);
    // The orientation of a symmetrically centered box is unchanged by flipping one local axis.
    if (orientation.determinant() < 0) {
        for (let row = 0; row < 3; row++) orientation.elements[row] *= -1;
    }
    return {
        bounds: local.applyMatrix4(stock.world),
        oriented: new OBB(
            new Vector3().setFromMatrixPosition(stock.world),
            stock.half.clone().addScalar(-minimumPenetration / 2),
            orientation,
        ),
    };
};

const reflectMachinedGeometry = (original) => {
    const geometry = original.clone().applyMatrix4(reflection);
    const index = geometry.getIndex();
    const count = index?.count ?? geometry.getAttribute('position').count;
    const reflected = [];
    for (let i = 0; i + 2 < count; i += 3) {
        reflected.push(index ? index.getX(i) : i, index ? index.getX(i + 2) : i + 2, index ? index.getX(i + 1) : i + 1);
    }
    geometry.setIndex(reflected);
    return geometry;
};

const usedBounds = (brush) => {
    const { geometry } = brush;
    const bounds = new Box3();
    const { index } = geometry;
    const position = geometry.getAttribute('position');
    const start = geometry.drawRange.start;
    const end = Math.min(index?.count ?? position.count, start + geometry.drawRange.count);
    const point = new Vector3();
    for (let offset = start; offset < end; offset++) {
        point.fromBufferAttribute(position, index ? index.getX(offset) : offset).applyMatrix4(brush.matrixWorld);
        bounds.expandByPoint(point);
    }
    return bounds;
};

const toMillimeters = (position) => ({
    x: position.x / millimeterScale, y: position.z / millimeterScale, z: position.y / millimeterScale,
});

export const analyzeAssemblyIntersections = (parts, geometryFor, { timeBudgetMs = 8000 } = {}) => {
    const started = performance.now();
    const entries = parts.flatMap(part => (part.instances ?? []).map(instance => {
        const stock = fastenerStockBounds({ part, instance });
        return { ...stock, ...stockBox(stock) };
    })).sort((a, b) => a.bounds.min.x - b.bounds.min.x || a.instance.id - b.instance.id);
    const evaluator = new Evaluator();
    evaluator.attributes = ['position'];
    evaluator.useGroups = false;
    const output = new Brush();
    const issues = [];
    let checkedPairs = 0;
    let failedPairs = 0;
    let issueCount = 0;
    let timeLimitReached = false;
    const brushes = [new Map(), new Map()];
    const mirroredGeometries = new Map();
    const getBrush = (entry, slot) => {
        const cache = brushes[slot];
        const mirrored = Boolean(entry.instance.mirrored);
        const key = `${entry.part.id}:${mirrored}`;
        if (!cache.has(key)) {
            const data = geometryFor(entry.part);
            if (data.state !== 'ready') return null;
            let geometry = data.geometry;
            if (mirrored && data.machined) {
                if (!mirroredGeometries.has(entry.part.id)) {
                    mirroredGeometries.set(entry.part.id, reflectMachinedGeometry(geometry));
                }
                geometry = mirroredGeometries.get(entry.part.id);
            }
            const brush = new Brush(geometry);
            brush.matrixAutoUpdate = false;
            cache.set(key, brush);
        }
        const brush = cache.get(key);
        brush.matrix.copy(entry.world);
        if (mirrored) brush.matrix.multiply(reflection);
        brush.updateMatrixWorld(true);
        return brush;
    };

    try {
        for (let i = 0; i < entries.length && !timeLimitReached; i++) {
            const a = entries[i];
            for (let j = i + 1; j < entries.length; j++) {
                if (performance.now() - started >= timeBudgetMs) {
                    timeLimitReached = true;
                    break;
                }
                const b = entries[j];
                if (a.bounds.max.x - b.bounds.min.x <= minimumPenetration) break;
                const box = a.bounds.clone().intersect(b.bounds);
                const span = box.getSize(new Vector3());
                if (Math.min(span.x, span.y, span.z) <= minimumPenetration || !a.oriented.intersectsOBB(b.oriented)) continue;
                try {
                    const left = getBrush(a, 0);
                    const right = getBrush(b, 1);
                    if (!left || !right) {
                        failedPairs++;
                        continue;
                    }
                    if (left.geometry.drawRange.count === 0 || right.geometry.drawRange.count === 0) {
                        checkedPairs++;
                        continue;
                    }
                    evaluator.evaluate(left, right, INTERSECTION, output);
                    if (!output.geometry.drawRange.count) {
                        checkedPairs++;
                        continue;
                    }
                    const volume = computeMeshVolume(output.geometry) / millimeterScale ** 3;
                    const upperBound = (span.x * span.y * span.z) / millimeterScale ** 3;
                    if (!Number.isFinite(volume) || volume > upperBound + Math.max(minimumVolumeMm3, upperBound * 0.001)) {
                        failedPairs++;
                        continue;
                    }
                    if (volume <= minimumVolumeMm3) {
                        checkedPairs++;
                        continue;
                    }
                    const overlap = usedBounds(output).intersect(box);
                    if (overlap.isEmpty()) {
                        failedPairs++;
                        continue;
                    }
                    checkedPairs++;
                    issueCount++;
                    if (issues.length < maxReportedIssues) {
                        issues.push({
                            code: 'material_intersection',
                            part_names: [a.part.name, b.part.name],
                            volume_mm3: Math.round(volume * 100) / 100,
                            context: {
                                instance_ids: [a.instance.id, b.instance.id],
                                intersection_bounds_mm: { min: toMillimeters(overlap.min), max: toMillimeters(overlap.max) },
                            },
                        });
                    }
                } catch {
                    failedPairs++;
                }
            }
        }
    } finally {
        output.geometry.dispose();
        for (const geometry of mirroredGeometries.values()) geometry.dispose();
    }

    return {
        status: timeLimitReached || failedPairs > 0 ? 'partial' : 'complete',
        instance_count: entries.length, checked_pairs: checkedPairs, failed_pairs: failedPairs,
        time_limit_reached: timeLimitReached, issue_count: issueCount,
        issues_truncated: issueCount > issues.length, issues,
    };
};
