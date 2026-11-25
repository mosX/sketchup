import { Box3, DoubleSide, Ray, Vector3 } from 'three';
import { Brush } from 'three-bvh-csg';
import { crossCutPlane, ripCutPlane } from './cutters.js';
import { drillPlacement, grooveFaceFrame, groovePlacement, millimeterScale, plungeRoutePlacement, roundoverEdgeFrame } from './coordinates.js';
import { calculateOutlinePositions } from './partOutline.js';

const basicFaces = ['top', 'left', 'start'];
const faceOrder = [...basicFaces, 'bottom', 'right', 'end'];
const outlineTolerance = 0.025 * millimeterScale;

const stockPoint = (frame, u, v) => frame.center.clone()
    .addScaledVector(frame.u, u - frame.uSize / 2).addScaledVector(frame.v, v - frame.vSize / 2);
const project = (frame, point) => [
    (point.dot(frame.u) + frame.uSize / 2) / millimeterScale,
    (point.dot(frame.v) + frame.vSize / 2) / millimeterScale,
];

const intersectCutWithFace = (frame, plane) => {
    const corners = [
        stockPoint(frame, 0, 0), stockPoint(frame, frame.uSize, 0),
        stockPoint(frame, frame.uSize, frame.vSize), stockPoint(frame, 0, frame.vSize),
    ];
    const distances = corners.map(point => point.clone().sub(plane.point).dot(plane.normal));
    const intersections = [];
    for (let index = 0; index < corners.length; index++) {
        const next = (index + 1) % corners.length;
        const a = distances[index];
        const b = distances[next];
        if (Math.abs(a) <= 1e-7) intersections.push(corners[index]);
        else if (a * b < 0) intersections.push(corners[index].clone().lerp(corners[next], a / (a - b)));
    }
    return intersections.length === 2 ? intersections : null;
};

const operationMarks = (part) => part.operations.flatMap((operation, index) => {
    const number = index + 1;
    if (operation.type === 'drill') {
        return [{ number, face: operation.face ?? 'top', point: drillPlacement(part, operation).surfacePoint }];
    }
    if (operation.type === 'groove') {
        return [{ number, face: operation.face ?? 'top', point: groovePlacement(part, operation).surfacePoint }];
    }
    if (operation.type === 'plunge_route') {
        const placement = plungeRoutePlacement(part, operation);
        const end = placement.startPoint.clone().addScaledVector(placement.path, placement.travelLength);
        return [{ number, face: operation.face ?? 'top', point: placement.startPoint, line: [placement.startPoint, end] }];
    }
    if (operation.type === 'edge_roundover') {
        const edge = operation.edge ?? 'top_left';
        const frame = roundoverEdgeFrame(part, edge);
        return [{ number, face: edge.split('_')[0], point: frame.corner }];
    }
    if (operation.type !== 'cross_cut' && operation.type !== 'rip_cut') return [];
    const face = operation.cut_direction === 'bottom_up' ? 'bottom' : 'top';
    const frame = grooveFaceFrame(part, face);
    const plane = operation.type === 'cross_cut' ? crossCutPlane(part, operation) : ripCutPlane(part, operation);
    const line = intersectCutWithFace(frame, plane);
    return [{ number, face, point: line ? line[0].clone().lerp(line[1], 0.5) : plane.point, line }];
});

const geometryBounds = (geometry) => {
    const positions = geometry.getAttribute('position');
    const { index, drawRange } = geometry;
    const bounds = new Box3();
    const point = new Vector3();
    const end = Math.min(index?.count ?? positions.count, drawRange.start + drawRange.count);
    for (let offset = drawRange.start; offset < end; offset++) {
        bounds.expandByPoint(point.fromBufferAttribute(positions, index ? index.getX(offset) : offset));
    }
    return bounds;
};

const silhouetteEdges = (geometry) => {
    const { index, drawRange, halfEdges } = geometry;
    const position = geometry.getAttribute('position');
    const start = drawRange.start;
    const end = Math.min(index?.count ?? position.count, start + drawRange.count);
    const normals = [];
    const read = (offset, out) => out.fromBufferAttribute(position, index ? index.getX(offset) : offset);
    const point = new Vector3();
    const left = new Vector3();
    const right = new Vector3();
    for (let offset = start; offset + 2 < end; offset += 3) {
        read(offset, point);
        read(offset + 1, left).sub(point);
        read(offset + 2, right).sub(point);
        normals[Math.floor(offset / 3)] = left.cross(right).clone().normalize();
    }
    const edges = [];
    for (let offset = start; offset < end; offset++) {
        const sibling = halfEdges.data[offset];
        if (sibling <= offset || sibling >= end) continue;
        const normalA = normals[Math.floor(offset / 3)];
        const normalB = normals[Math.floor(sibling / 3)];
        if (normalA.dot(normalB) > 1 - 1e-8) continue;
        const corner = offset % 3;
        edges.push({
            from: read(offset, left).toArray(),
            to: read(offset - corner + (corner + 1) % 3, right).toArray(),
            normalA, normalB,
        });
    }
    return edges;
};

const visibleFaceLines = (frame, edges, tree) => {
    const lines = [];
    const from = new Vector3();
    const to = new Vector3();
    const point = new Vector3();
    const ray = new Ray(new Vector3(), frame.inward);
    const distance = frame.maximumDepth + 0.1;
    for (let offset = 0; offset + 5 < edges.length; offset += 6) {
        from.fromArray(edges, offset);
        to.fromArray(edges, offset + 3);
        const start = project(frame, from);
        const end = project(frame, to);
        const length = Math.hypot(end[0] - start[0], end[1] - start[1]);
        if (length < 0.01) continue;
        const samples = Math.max(1, Math.min(12, Math.ceil(length / 4)));
        let visibleStart = null;
        for (let segment = 0; segment <= samples; segment++) {
            let visible = false;
            if (segment < samples) {
                point.lerpVectors(from, to, (segment + 0.5) / samples);
                ray.origin.copy(point).addScaledVector(frame.inward, -distance);
                visible = !tree.raycastFirst(ray, DoubleSide, 0, distance - outlineTolerance);
            }
            if (visible && visibleStart === null) visibleStart = segment / samples;
            if (!visible && visibleStart !== null) {
                const at = (factor) => start.map((value, axis) => value + (end[axis] - value) * factor);
                lines.push([at(visibleStart), at(segment / samples)]);
                visibleStart = null;
            }
        }
    }
    return lines;
};

export const buildWorkshopDrawing = (part, geometry = null) => {
    const marks = operationMarks(part);
    let outline = null;
    let silhouettes = [];
    let tree = null;
    let finished = null;
    let status = part.operations.length ? 'stock_only' : 'complete';
    if (geometry) {
        const bounds = geometryBounds(geometry);
        if (bounds.isEmpty()) {
            status = 'empty';
        } else {
            const size = bounds.getSize(new Vector3()).divideScalar(millimeterScale);
            finished = [size.x, size.z, size.y];
            outline = geometry.userData.outlinePositions ?? calculateOutlinePositions(geometry);
            new Brush(geometry).prepareGeometry();
            tree = geometry.boundsTree;
            silhouettes = silhouetteEdges(geometry);
            status = 'complete';
        }
    } else if (status === 'complete') {
        finished = ['length', 'width', 'thickness'].map(key => Number(part.dimensions[key]));
    }

    const views = faceOrder.filter(face => basicFaces.includes(face) || marks.some(mark => mark.face === face)).map(face => {
        const frame = grooveFaceFrame(part, face);
        const width = frame.uSize / millimeterScale;
        const height = frame.vSize / millimeterScale;
        const corners = [[0, 0], [width, 0], [width, height], [0, height]];
        const stock = corners.map((point, index) => [point, corners[(index + 1) % corners.length]]);
        const frontFacing = normal => normal.dot(frame.inward) < -1e-6;
        const boundaries = silhouettes.flatMap(({ from, to, normalA, normalB }) =>
            frontFacing(normalA) !== frontFacing(normalB) ? [...from, ...to] : []);
        return {
            face, width, height,
            lines: tree ? [
                ...visibleFaceLines(frame, outline, tree),
                ...visibleFaceLines(frame, boundaries, tree),
            ] : !geometry && status === 'complete' ? stock : [],
            marks: marks.filter(mark => mark.face === face).map(mark => ({
                number: mark.number,
                at: project(frame, mark.point),
                line: mark.line?.map(point => project(frame, point)),
            })),
        };
    });
    return { status, finished, views };
};
