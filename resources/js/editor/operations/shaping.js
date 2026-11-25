import { roundoverEdgeLabels } from './roundover.js';
import { surfaceDimensions } from './shared.js';

export const shapingLabels = {
    rabbet: 'Выборка четверти',
    pocket: 'Карман фрезером',
    edge_chamfer: 'Фаска на кромке',
    jigsaw_cut: 'Фигурный рез',
};

export const faceAxes = { top: 'thickness', bottom: 'thickness', left: 'width', right: 'width', start: 'length', end: 'length' };

export const maximumChamferWidth = (edge, dimensions) => Math.min(...edge.split('_').map(face => Number(dimensions[faceAxes[face]]))) - 0.1;

export const rabbetAxes = (edge, face) => {
    const adjacent = edge.split('_');
    const depth = faceAxes[face];
    const width = faceAxes[adjacent.find(side => side !== face)];
    const length = ['length', 'width', 'thickness'].find(axis => axis !== depth && axis !== width);
    return { depth, width, length };
};

export const contourPresets = (face, dimensions, shape = 'oval') => {
    const { u, v } = surfaceDimensions(face, dimensions);
    if (shape === 'notch') return [
        { u: u * 0.25, v: -v * 0.1 }, { u: u * 0.75, v: -v * 0.1 },
        ...Array.from({ length: 17 }, (_, index) => {
            const angle = Math.PI * index / 16;
            return { u: u * (0.5 + Math.cos(angle) * 0.25), v: Math.sin(angle) * v * 0.28 };
        }),
    ].map(roundContourPoint);
    return Array.from({ length: 32 }, (_, index) => {
        const angle = 2 * Math.PI * index / 32;
        return roundContourPoint({ u: u * (0.5 + Math.cos(angle) * 0.2), v: v * (0.5 + Math.sin(angle) * 0.2) });
    });
};

export const roundContourPoint = ({ u, v }) => ({
    u: Math.round(u * 10000) / 10000, v: Math.round(v * 10000) / 10000,
});

const contourTolerance = 1e-8;
const cross = (a, b, c) => (b.u - a.u) * (c.v - a.v) - (b.v - a.v) * (c.u - a.u);
const within = (value, a, b) => value >= Math.min(a, b) - contourTolerance && value <= Math.max(a, b) + contourTolerance;
const turn = (a, b, c) => Math.abs(cross(a, b, c)) <= contourTolerance ? 0 : Math.sign(cross(a, b, c));
const intersects = (a, b, c, d) => {
    const abc = turn(a, b, c), abd = turn(a, b, d), cda = turn(c, d, a), cdb = turn(c, d, b);
    const on = (point, start, end) => within(point.u, start.u, end.u) && within(point.v, start.v, end.v);
    return (abc * abd < 0 && cda * cdb < 0)
        || (abc === 0 && on(c, a, b)) || (abd === 0 && on(d, a, b))
        || (cda === 0 && on(a, c, d)) || (cdb === 0 && on(b, c, d));
};

export const isSimplePath = (points, closed, limit = 64) => {
    if (!Array.isArray(points) || points.length < (closed ? 3 : 2) || points.length > limit
        || points.some(point => point?.u == null || point?.v == null || !Number.isFinite(Number(point.u)) || !Number.isFinite(Number(point.v)))) return false;
    const vertices = points.map(({ u, v }) => ({ u: Number(u), v: Number(v) }));
    const segments = vertices.length - (closed ? 0 : 1);
    let doubledArea = 0;
    for (let index = 0; index < segments; index++) {
        const a = vertices[index], b = vertices[(index + 1) % vertices.length];
        if (Math.hypot(a.u - b.u, a.v - b.v) < 0.001) return false;
        const c = closed ? vertices[(index + 2) % vertices.length] : vertices[index + 2];
        if (c && !turn(a, b, c) && (a.u - b.u) * (c.u - b.u) + (a.v - b.v) * (c.v - b.v) > 0) return false;
        doubledArea += cross(vertices[0], a, b);
        for (let other = index + 2; other < segments; other++) {
            if (closed && index === 0 && other === segments - 1) continue;
            if (intersects(a, b, vertices[other], vertices[(other + 1) % vertices.length])) return false;
        }
    }
    return !closed || Math.abs(doubledArea) > 0.0001;
};
export const isSimpleContour = points => isSimplePath(points, true);

export const fitShaping = (operation, dimensions) => {
    const bound = (value, minimum, maximum) => Math.min(Math.max(Number(value) || minimum, minimum), Math.max(minimum, maximum));
    if (operation.type === 'edge_chamfer') return {
        ...operation, width: bound(operation.width, 0.1, maximumChamferWidth(operation.edge, dimensions)),
    };
    if (operation.type === 'rabbet') {
        const axes = rabbetAxes(operation.edge, operation.face);
        const start = bound(operation.start, 0, dimensions[axes.length] - 0.1);
        return {
            ...operation, start,
            cut_length: bound(operation.cut_length, 0.1, dimensions[axes.length] - start),
            width: bound(operation.width, 0.1, dimensions[axes.width] - 0.1),
            depth: bound(operation.depth, 0.1, dimensions[axes.depth] - 0.1),
        };
    }
    if (operation.type === 'pocket') {
        const face = surfaceDimensions(operation.face, dimensions);
        const circular = operation.pocket_shape === 'circle';
        const diameter = bound(operation.diameter, 0.1, Math.min(face.u, face.v) - 0.2);
        const u = circular ? diameter : bound(operation.pocket_length, 0.1, face.u - 0.2);
        const v = circular ? diameter : bound(operation.width, 0.1, face.v - 0.2);
        return {
            ...operation, pocket_length: u, width: v, diameter,
            corner_radius: bound(operation.corner_radius, 0, Math.min(u, v) / 2),
            center_u: bound(operation.center_u, u / 2 + 0.1, face.u - u / 2 - 0.1),
            center_v: bound(operation.center_v, v / 2 + 0.1, face.v - v / 2 - 0.1),
            depth: bound(operation.depth, 0.1, face.depth - 0.1),
        };
    }
    return operation;
};

export const createShaping = (common, dimensions) => {
    const defaults = {
        rabbet: { edge: 'top_left', face: 'top', start: 0, cut_length: dimensions.length, width: 10, depth: 5 },
        edge_chamfer: { edge: 'top_left', width: 3 },
        pocket: {
            face: 'top', pocket_shape: 'rectangle', center_u: Number(dimensions.length) / 2, center_v: Number(dimensions.width) / 2,
            pocket_length: 60, width: 35, corner_radius: 3, diameter: 35, depth: 5,
        },
        jigsaw_cut: { face: 'top', points: contourPresets('top', dimensions) },
    };
    return fitShaping({ ...common, ...defaults[common.type] }, dimensions);
};

export const shapingSummary = (operation) => {
    const { type, edge, width, depth } = operation;
    if (type === 'edge_chamfer') return `${roundoverEdgeLabels[edge]} · ${width} мм × 45°`;
    if (type === 'rabbet') return `${roundoverEdgeLabels[edge]} · ${width} × ${depth} мм · от ${operation.start} мм, длина ${operation.cut_length} мм`;
    if (type === 'pocket') return `${operation.pocket_shape === 'circle' ? `Ø${operation.diameter}` : `${operation.pocket_length} × ${width}${Number(operation.corner_radius) ? ` · R${operation.corner_radius}` : ''}`} мм · глубина ${depth} мм`;
    return `${operation.points?.length ?? 0} точек · сквозной`;
};
