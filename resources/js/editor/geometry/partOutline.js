import { BufferGeometry, Float32BufferAttribute, Triangle, Vector3 } from 'three';
import { HalfEdgeMap } from 'three-bvh-csg';

const creaseDot = Math.cos(12 * Math.PI / 180);
const minimumLength = 1e-6;
const edgeTolerance = 2e-5;
const directionGrid = 1000;

const directionCell = (direction) => [
    Math.round(direction.x * directionGrid),
    Math.round(direction.y * directionGrid),
    Math.round(direction.z * directionGrid),
];

export const calculateOutlinePositions = (geometry) => {
    const position = geometry.getAttribute('position');
    const index = geometry.getIndex();
    const start = geometry.drawRange.start;
    const end = Math.min(start + geometry.drawRange.count, index?.count ?? position?.count ?? 0);
    if (end - start < 3) return new Float32Array();

    const adjacency = new HalfEdgeMap();
    adjacency.updateFrom(geometry);

    const read = (offset, target) => target.fromBufferAttribute(position, index ? index.getX(offset) : offset);
    const readEdge = (offset, first, last) => {
        const corner = offset % 3;
        read(offset, first);
        read(offset - corner + (corner + 1) % 3, last);
    };
    const triangle = new Triangle();
    const normals = [];
    const sideA = new Vector3();
    const sideB = new Vector3();
    for (let offset = start; offset + 2 < end; offset += 3) {
        read(offset, triangle.a);
        read(offset + 1, triangle.b);
        read(offset + 2, triangle.c);
        sideA.subVectors(triangle.b, triangle.a);
        sideB.subVectors(triangle.c, triangle.a);
        const longestSquared = Math.max(sideA.lengthSq(), sideB.lengthSq(), triangle.b.distanceToSquared(triangle.c));
        sideA.cross(sideB);
        if (sideA.lengthSq() <= longestSquared * minimumLength ** 2) continue;
        normals[Math.floor(offset / 3)] = sideA.clone().normalize();
    }

    const segments = [];
    const first = new Vector3();
    const last = new Vector3();
    const point = new Vector3();
    const delta = new Vector3();
    const unmatched = [];
    const byDirection = new Map();
    const append = (v) => segments.push(v.x, v.y, v.z);
    const isCrease = (left, right) => {
        const a = normals[Math.floor(left / 3)];
        const b = normals[Math.floor(right / 3)];
        return a && b && Math.abs(a.dot(b)) < creaseDot;
    };

    for (let edge = start; edge < end; edge++) {
        const sibling = adjacency.data[edge];
        if (sibling !== -1) {
            if (sibling > edge && isCrease(edge, sibling)) {
                readEdge(edge, first, last);
                if (first.distanceToSquared(last) > minimumLength ** 2) {
                    append(first);
                    append(last);
                }
            }
            continue;
        }

        readEdge(edge, first, last);
        const direction = new Vector3().subVectors(last, first);
        const length = direction.length();
        if (length <= minimumLength) continue;
        direction.divideScalar(length);
        const cell = directionCell(direction);
        const key = cell.join(',');
        const fragment = { edge, start: first.clone(), end: last.clone(), direction, length, cell };
        if (!byDirection.has(key)) byDirection.set(key, []);
        byDirection.get(key).push(fragment);
        unmatched.push(fragment);
    }

    // A cut can leave one long edge opposite several shorter edges. Only draw their shared spans.
    for (const fragment of unmatched) {
        const { edge, start: origin, direction, length, cell: [x, y, z] } = fragment;
        for (let dx = -1; dx <= 1; dx++) {
            for (let dy = -1; dy <= 1; dy++) {
                for (let dz = -1; dz <= 1; dz++) {
                    const opposite = byDirection.get(`${-x + dx},${-y + dy},${-z + dz}`);
                    if (!opposite) continue;
                    for (const other of opposite) {
                        if (other.edge <= edge || !isCrease(edge, other.edge)) continue;
                        const a = delta.subVectors(other.start, origin).dot(direction);
                        if (delta.addScaledVector(direction, -a).lengthSq() > edgeTolerance ** 2) continue;
                        const b = delta.subVectors(other.end, origin).dot(direction);
                        if (delta.addScaledVector(direction, -b).lengthSq() > edgeTolerance ** 2) continue;
                        const from = Math.max(0, Math.min(a, b));
                        const to = Math.min(length, Math.max(a, b));
                        if (to - from <= minimumLength) continue;
                        append(point.copy(origin).addScaledVector(direction, from));
                        append(point.copy(origin).addScaledVector(direction, to));
                    }
                }
            }
        }
    }

    return new Float32Array(segments);
};

export const createPartOutline = (geometry) => new BufferGeometry().setAttribute(
    'position',
    new Float32BufferAttribute(geometry.userData.outlinePositions ?? calculateOutlinePositions(geometry), 3),
);
