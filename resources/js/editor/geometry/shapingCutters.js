import * as THREE from 'three';
import { Brush } from 'three-bvh-csg';
import { grooveFaceFrame, millimeterScale, roundoverEdgeFrame } from './coordinates.js';
import { isSimpleContour, rabbetAxes } from '../operations/shaping.js';

const epsilon = 0.002;
const millimeters = value => Number(value) * millimeterScale;
const axisVector = axis => new THREE.Vector3(Number(axis === 'x'), Number(axis === 'y'), Number(axis === 'z'));

export const pointOnStockFace = (frame, u, v) => frame.center.clone()
    .addScaledVector(frame.u, millimeters(u) - frame.uSize / 2)
    .addScaledVector(frame.v, millimeters(v) - frame.vSize / 2);

export const rabbetPlacement = (part, operation) => {
    const edge = roundoverEdgeFrame(part, operation.edge);
    const axes = rabbetAxes(operation.edge, operation.face);
    const inward = grooveFaceFrame(part, operation.face).inward;
    const across = edge.inwardA.equals(inward) ? edge.inwardB : edge.inwardA;
    const axis = axisVector(edge.axis);
    const start = millimeters(operation.start);
    const length = millimeters(operation.cut_length);
    return { axes, edge, axis, inward, across, start, length };
};

const extruded = (shape, depth, origin, u, v, inward, curveSegments = 12) => {
    const geometry = new THREE.ExtrudeGeometry(shape, { depth, bevelEnabled: false, steps: 1, curveSegments });
    const brush = new Brush(geometry);
    brush.quaternion.setFromRotationMatrix(new THREE.Matrix4().makeBasis(u, v, inward));
    brush.position.copy(origin);
    return brush;
};

const rabbetCutter = (part, operation) => {
    const { edge, axis, inward, across, start, length } = rabbetPlacement(part, operation);
    const width = millimeters(operation.width);
    const depth = millimeters(operation.depth);
    const lead = start < 1e-8 ? epsilon : 0;
    const trail = start + length >= edge.extent - 1e-8 ? epsilon : 0;
    const lengthVector = axis.clone().multiplyScalar(length + lead + trail);
    const dimensions = lengthVector.addScaledVector(across, width + epsilon).addScaledVector(inward, depth + epsilon);
    const brush = new Brush(new THREE.BoxGeometry(...dimensions.toArray().map(Math.abs)));
    brush.position.copy(edge.corner)
        .addScaledVector(axis, start - edge.extent / 2 + (length + trail - lead) / 2)
        .addScaledVector(across, (width - epsilon) / 2)
        .addScaledVector(inward, (depth - epsilon) / 2);
    return brush;
};

const chamferCutter = (part, operation) => {
    const { corner, extent, inwardA, inwardB } = roundoverEdgeFrame(part, operation.edge);
    const axial = inwardA.clone().cross(inwardB);
    const width = millimeters(operation.width);
    const shape = new THREE.Shape([
        new THREE.Vector2(-epsilon, -epsilon),
        new THREE.Vector2(width + epsilon, -epsilon),
        new THREE.Vector2(-epsilon, width + epsilon),
    ]);
    const depth = extent + 2 * epsilon;
    return extruded(shape, depth, corner.clone().addScaledVector(axial, -depth / 2), inwardA, inwardB, axial);
};

const pocketShape = (operation) => {
    const shape = new THREE.Shape();
    if (operation.pocket_shape === 'circle') {
        const radius = millimeters(operation.diameter) / 2;
        shape.absarc(0, 0, radius, 0, 2 * Math.PI, false);
        return shape;
    }
    const u = millimeters(operation.pocket_length) / 2;
    const v = millimeters(operation.width) / 2;
    const r = Math.min(millimeters(operation.corner_radius ?? 0), u, v);
    if (!r) return new THREE.Shape([
        new THREE.Vector2(-u, -v), new THREE.Vector2(u, -v),
        new THREE.Vector2(u, v), new THREE.Vector2(-u, v),
    ]);
    shape.absarc(u - r, -v + r, r, -Math.PI / 2, 0, false);
    shape.absarc(u - r, v - r, r, 0, Math.PI / 2, false);
    shape.absarc(-u + r, v - r, r, Math.PI / 2, Math.PI, false);
    shape.absarc(-u + r, -v + r, r, Math.PI, 3 * Math.PI / 2, false);
    shape.closePath();
    return shape;
};

const faceCutter = (part, operation) => {
    const frame = grooveFaceFrame(part, operation.face);
    if (!frame) return null;
    if (operation.type === 'pocket') {
        const origin = pointOnStockFace(frame, operation.center_u, operation.center_v).addScaledVector(frame.inward, -epsilon);
        const segments = operation.pocket_shape === 'circle' ? 32 : 12;
        return extruded(pocketShape(operation), millimeters(operation.depth) + epsilon, origin, frame.u, frame.v, frame.inward, segments);
    }
    if (!isSimpleContour(operation.points)) return null;
    const shape = new THREE.Shape(operation.points.map(({ u, v }) => new THREE.Vector2(millimeters(u), millimeters(v))));
    const origin = pointOnStockFace(frame, 0, 0).addScaledVector(frame.inward, -epsilon);
    return extruded(shape, frame.maximumDepth + 2 * epsilon, origin, frame.u, frame.v, frame.inward);
};

export const shapingCutter = (part, operation) => {
    if (operation.type === 'rabbet') return rabbetCutter(part, operation);
    if (operation.type === 'edge_chamfer') return chamferCutter(part, operation);
    if (['pocket', 'jigsaw_cut'].includes(operation.type)) return faceCutter(part, operation);
    return null;
};

export const shapingOutline = (part, operation) => {
    if (operation.type === 'rabbet') {
        const { edge, axis, across, start, length } = rabbetPlacement(part, operation);
        const first = edge.corner.clone().addScaledVector(axis, start - edge.extent / 2);
        const last = first.clone().addScaledVector(axis, length);
        const width = millimeters(operation.width);
        return { face: operation.face, points: [first, last, last.clone().addScaledVector(across, width), first.clone().addScaledVector(across, width), first] };
    }
    if (operation.type === 'edge_chamfer') {
        const edge = roundoverEdgeFrame(part, operation.edge);
        const axis = axisVector(edge.axis);
        return { face: operation.edge.split('_')[0], points: [edge.corner.clone().addScaledVector(axis, -edge.extent / 2), edge.corner.clone().addScaledVector(axis, edge.extent / 2)] };
    }
    const frame = grooveFaceFrame(part, operation.face);
    if (operation.type === 'pocket') {
        const center = pointOnStockFace(frame, operation.center_u, operation.center_v);
        const segments = operation.pocket_shape === 'circle' ? 32 : 12;
        const points = pocketShape(operation).getPoints(segments).map(({ x, y }) => center.clone().addScaledVector(frame.u, x).addScaledVector(frame.v, y));
        return { face: operation.face, points: [...points, points[0]] };
    }
    if (!isSimpleContour(operation.points)) return null;
    const points = operation.points.map(({ u, v }) => pointOnStockFace(frame, u, v));
    return { face: operation.face, points: [...points, points[0]] };
};
