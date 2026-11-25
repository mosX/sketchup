import * as THREE from 'three';
import { ADDITION, Brush, Evaluator } from 'three-bvh-csg';
import { ConvexGeometry } from 'three/addons/geometries/ConvexGeometry.js';
import { mergeGeometries } from 'three/addons/utils/BufferGeometryUtils.js';
import { drillRowCenters, machiningFlag, routeEndRadii, validRoute } from '../operations/advancedMachining.js';
import { isSimplePath } from '../operations/shaping.js';
import { grooveFaceFrame, millimeterScale, partSize } from './coordinates.js';
import { pointOnStockFace } from './shapingCutters.js';

const m = value => Number(value) * millimeterScale;
const epsilon = 0.002;
const vector = (u, v) => new THREE.Vector2(u, v);
const sceneY = new THREE.Vector3(0, 1, 0);

export const pocketScrewPlacement = (part, operation) => {
    const frame = grooveFaceFrame(part, operation.face);
    const angle = THREE.MathUtils.degToRad(Number(operation.entry_angle));
    const heading = THREE.MathUtils.degToRad(Number(operation.path_angle));
    const start = pointOnStockFace(frame, operation.center_u, operation.center_v);
    const direction = frame.u.clone().multiplyScalar(Math.cos(angle) * Math.cos(heading))
        .addScaledVector(frame.v, Math.cos(angle) * Math.sin(heading)).addScaledVector(frame.inward, Math.sin(angle));
    return { frame, start, direction, reach: m(Number(operation.depth) + Number(operation.pilot_length)) };
};

const rowCutter = (part, operation) => {
    const frame = grooveFaceFrame(part, operation.face);
    const through = machiningFlag(operation.through);
    const depth = through ? frame.maximumDepth : m(operation.depth);
    const radius = m(operation.diameter) / 2;
    const extension = through ? epsilon : 0;
    const orientation = new THREE.Quaternion().setFromUnitVectors(sceneY, frame.inward);
    const cylinders = drillRowCenters(operation).map(({ u, v }) => {
        const center = pointOnStockFace(frame, u, v).addScaledVector(frame.inward, (depth + extension - epsilon) / 2);
        return new THREE.CylinderGeometry(radius, radius, depth + epsilon + extension, 32).applyQuaternion(orientation).translate(...center);
    });
    const geometry = mergeGeometries(cylinders);
    cylinders.forEach(cylinder => cylinder.dispose());
    return new Brush(geometry);
};

const screwCutter = (part, operation) => {
    const placement = pocketScrewPlacement(part, operation);
    const radius = m(operation.diameter) / 2;
    const pilot = m(operation.pilot_diameter) / 2;
    const shoulder = m(operation.depth);
    const lead = -radius / Math.tan(THREE.MathUtils.degToRad(Number(operation.entry_angle))) - epsilon;
    const profile = [[0, lead], [radius, lead], [radius, shoulder], [pilot, shoulder], [pilot, placement.reach], [0, placement.reach]];
    const brush = new Brush(new THREE.LatheGeometry(profile.map(([x, y]) => vector(x, y)), 48));
    brush.position.copy(placement.start);
    brush.quaternion.setFromUnitVectors(sceneY, placement.direction);
    return brush;
};

export const routeCenterline = (operation) => {
    const points = operation.points.map(({ u, v }) => vector(Number(u), Number(v)));
    if (!machiningFlag(operation.smooth_path) || points.length < 3) return points;
    const closed = machiningFlag(operation.closed_path);
    return points.flatMap((point, index) => {
        if (!closed && (index === 0 || index === points.length - 1)) return [point];
        const previous = points[(index - 1 + points.length) % points.length];
        const next = points[(index + 1) % points.length];
        if (Math.abs(point.clone().sub(previous).normalize().cross(next.clone().sub(point).normalize())) < 1e-7) return [point];
        const before = point.clone().lerp(previous, 0.3);
        const after = point.clone().lerp(next, 0.3);
        return new THREE.QuadraticBezierCurve(before, point, after).getPoints(4);
    });
};

const perpendicular = ({ x, y }) => vector(-y, x);
const offsetPath = (points, closed, radius) => points.map((point, index) => {
    const n = points.length;
    const incoming = point.clone().sub(points[(index - 1 + n) % n]).normalize();
    const outgoing = points[(index + 1) % n].clone().sub(point).normalize();
    const start = perpendicular(!closed && index === 0 ? outgoing : incoming);
    const end = perpendicular(!closed && index === n - 1 ? incoming : outgoing);
    const bisector = start.add(end).normalize();
    const alignment = bisector.dot(end);
    return point.clone().addScaledVector(bisector, radius / Math.max(0.32, alignment));
});

const circleArc = (point, normal, radius) => {
    const angle = Math.atan2(normal.y, normal.x);
    return Array.from({ length: 7 }, (_, index) => {
        const step = angle - (index + 1) * Math.PI / 8;
        return point.clone().add(vector(Math.cos(step) * radius, Math.sin(step) * radius));
    });
};

const stroke = (points, closed, radius) => {
    const left = offsetPath(points, closed, radius);
    const right = offsetPath(points, closed, -radius);
    if (closed) {
        const positive = THREE.ShapeUtils.area(points) > 0;
        return positive ? [right, left.reverse()] : [left.reverse(), right];
    }
    const last = points.length - 1;
    const ending = circleArc(points[last], left[last].clone().sub(points[last]), radius);
    const starting = circleArc(points[0], right[0].clone().sub(points[0]), radius);
    return [[...left, ...ending, ...right.reverse(), ...starting].reverse()];
};

const ringValid = ring => isSimplePath(ring.map(({ x, y }) => ({ u: x, v: y })), true, 350);

export const curveRouteProfiles = (part, operation) => {
    if (!validRoute(operation, part.dimensions)) return null;
    const points = routeCenterline(operation);
    const [surface, bottom] = routeEndRadii(operation);
    const closed = machiningFlag(operation.closed_path);
    const upper = stroke(points, closed, surface);
    const lower = stroke(points, closed, bottom);
    if (![upper, lower].every(rings => rings.every((ring, index) => ringValid(ring) && (THREE.ShapeUtils.area(ring) > 0) === (index === 0)))) return null;
    return { upper, lower };
};

const loft = (layers) => {
    const positions = [];
    const put = (point, z) => positions.push(m(point.x), m(point.y), z);
    for (let layer = 1; layer < layers.length; layer++) {
        const [low, high] = [layers[layer - 1], layers[layer]];
        low.rings.forEach((bottom, index) => {
            const top = high.rings[index];
            for (let a = 0; a < bottom.length; a++) {
                const b = (a + 1) % bottom.length;
                put(bottom[a], low.z); put(bottom[b], low.z); put(top[b], high.z);
                put(bottom[a], low.z); put(top[b], high.z); put(top[a], high.z);
            }
        });
    }
    for (const [layer, positive] of [[layers[0], false], [layers.at(-1), true]]) {
        const [outline, ...holes] = layer.rings;
        const points = layer.rings.flat();
        const faces = THREE.ShapeUtils.triangulateShape(outline.slice(), holes.map(hole => hole.slice()));
        for (const [a, b, c] of faces) {
            const upward = points[b].clone().sub(points[a]).cross(points[c].clone().sub(points[a])) > 0;
            (upward === positive ? [a, b, c] : [a, c, b]).forEach(i => put(points[i], layer.z));
        }
    }
    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    geometry.setAttribute('uv', new THREE.Float32BufferAttribute(new Float32Array(positions.length / 3 * 2), 2));
    geometry.computeVertexNormals();
    return geometry;
};

const routeLoft = ({ upper, lower }, depth) => loft([
    { rings: upper, z: -epsilon }, { rings: upper, z: 0 }, { rings: lower, z: depth },
]);

const overlappingRouteCutter = (operation) => {
    const centerline = routeCenterline(operation);
    const points = centerline.length <= 64 ? centerline : centerline.filter((_, index) => index % 3 === 0 || index === centerline.length - 1);
    if (machiningFlag(operation.closed_path)) points.push(points[0]);
    const [surface, bottom] = routeEndRadii(operation);
    const evaluator = new Evaluator();
    evaluator.useGroups = false;
    let result = null;
    for (let index = 1; index < points.length; index++) {
        const segment = [points[index - 1], points[index]];
        const geometry = routeLoft({ upper: stroke(segment, false, surface), lower: stroke(segment, false, bottom) }, m(operation.depth));
        const brush = new Brush(geometry);
        brush.updateMatrixWorld();
        if (!result) {
            result = brush;
            continue;
        }
        const previous = result;
        try {
            previous.updateMatrixWorld();
            result = evaluator.evaluate(previous, brush, ADDITION);
        } finally {
            previous.geometry.dispose();
            brush.geometry.dispose();
        }
    }
    return result;
};

const routeCutter = (part, operation) => {
    if (!validRoute(operation, part.dimensions)) return null;
    const frame = grooveFaceFrame(part, operation.face);
    const profiles = curveRouteProfiles(part, operation);
    const brush = profiles ? new Brush(routeLoft(profiles, m(operation.depth))) : overlappingRouteCutter(operation);
    brush.quaternion.setFromRotationMatrix(new THREE.Matrix4().makeBasis(frame.u, frame.v, frame.inward));
    brush.position.copy(pointOnStockFace(frame, 0, 0));
    return brush;
};

const taperCutter = (part, operation) => {
    const { length, thickness } = partSize(part);
    const heights = operation.taper_sides === 'two' ? [thickness, thickness] : [m(operation.start_height), m(operation.end_height)];
    const widths = [m(operation.start_width), m(operation.end_width)];
    const safeExtension = ([start, end]) => Math.min(start, end) * length / Math.max(Math.abs(end - start) * 2, 1e-9);
    const extension = Math.min(epsilon, safeExtension(widths), safeExtension(heights));
    const points = widths.flatMap((width, index) => {
        const sign = index ? 1 : -1;
        const x = sign * (length / 2 + extension);
        const endWidth = width + sign * (widths[1] - widths[0]) * extension / length;
        const endHeight = heights[index] + sign * (heights[1] - heights[0]) * extension / length;
        return [-1, 1].flatMap(y => [-1, 1].map(z => new THREE.Vector3(x, y * (endHeight / 2 + 1e-6), z * (endWidth / 2 + 1e-6))));
    });
    const geometry = new ConvexGeometry(points);
    geometry.setAttribute('uv', new THREE.Float32BufferAttribute(new Float32Array(geometry.getAttribute('position').count * 2), 2));
    return new Brush(geometry);
};

export const turningProfile = (part, operation) => {
    const length = m(part.dimensions.length);
    const points = operation.stations.map(({ at, diameter }) => vector(m(diameter) / 2, m(at) - length / 2));
    if (!machiningFlag(operation.smooth_profile)) return points;
    return points.flatMap((point, index) => {
        if (index === 0) return [point];
        const previous = points[index - 1];
        return Array.from({ length: 8 }, (_, step) => {
            const t = (step + 1) / 8;
            const eased = t * t * (3 - 2 * t);
            return vector(THREE.MathUtils.lerp(previous.x, point.x, eased), THREE.MathUtils.lerp(previous.y, point.y, t));
        });
    });
};

const turningCutter = (part, operation) => {
    const profile = turningProfile(part, operation);
    const first = profile[0], last = profile.at(-1);
    profile.unshift(vector(0, first.y - epsilon), vector(first.x, first.y - epsilon));
    profile.push(vector(last.x, last.y + epsilon), vector(0, last.y + epsilon));
    const brush = new Brush(new THREE.LatheGeometry(profile, 64));
    brush.quaternion.setFromUnitVectors(sceneY, new THREE.Vector3(1, 0, 0));
    return brush;
};

export const advancedCutter = (part, operation) => ({
    drill_row: rowCutter, pocket_screw: screwCutter, curve_route: routeCutter, taper: taperCutter, turning: turningCutter,
}[operation.type]?.(part, operation) ?? null);
