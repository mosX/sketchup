import { surfaceDimensions } from './shared.js';

const fittedDiameter = (diameter, surface) => Math.max(0.1, Math.min(Number(diameter), surface.u, surface.v));

export const createDrillOperation = (common, dimensions) => {
    const surface = surfaceDimensions('top', dimensions);
    const diameter = fittedDiameter(8, surface);

    return {
        ...common,
        face: 'top',
        center_u: surface.u / 2,
        center_v: surface.v / 2,
        diameter,
        depth: Math.min(18, surface.depth),
        through: false,
    };
};

export const normalizeDrill = (operation, part) => {
    const face = operation.face ?? 'top';
    const surface = surfaceDimensions(face, part.dimensions);
    const diameter = fittedDiameter(operation.diameter ?? 8, surface);

    return {
        ...operation,
        face,
        center_u: Number(operation.center_u ?? surface.u / 2),
        center_v: Number(operation.center_v ?? surface.v / 2),
        diameter,
        depth: Math.min(Number(operation.depth ?? Math.min(18, surface.depth)), surface.depth),
        through: Boolean(operation.through ?? false),
    };
};

export const fitDrillToFace = (operation, face, dimensions) => {
    const surface = surfaceDimensions(face, dimensions);
    const diameter = fittedDiameter(operation.diameter, surface);

    return {
        face,
        center_u: surface.u / 2,
        center_v: surface.v / 2,
        diameter,
        depth: operation.through ? surface.depth : Math.min(Number(operation.depth), surface.depth),
    };
};
