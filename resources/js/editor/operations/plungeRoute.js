import { surfaceDimensions } from './shared.js';

export const plungeProfileMaximumDepth = (operation, maximumSurfaceDepth) => {
    if (!operation) return 0.1;

    const radius = Number(operation.cutter_diameter) / 2;
    const cutterAngle = Number(operation.cutter_angle ?? 0);

    if (operation.cutter_profile === 'dovetail') {
        return Math.max(Math.min(maximumSurfaceDepth, (radius - 0.05) / Math.tan(cutterAngle * Math.PI / 180)), 0.1);
    }

    if (operation.cutter_profile === 'v_groove') {
        return Math.max(Math.min(maximumSurfaceDepth, radius / Math.tan(cutterAngle / 2 * Math.PI / 180)), 0.1);
    }

    return maximumSurfaceDepth;
};

export const plungeOperationRadius = (operation) => {
    const nominalRadius = Number(operation.cutter_diameter) / 2;

    if (operation.cutter_profile === 'v_groove') {
        return Math.min(nominalRadius, Number(operation.depth) * Math.tan(Number(operation.cutter_angle) / 2 * Math.PI / 180));
    }

    return nominalRadius;
};

export const createPlungeRouteOperation = (common, dimensions) => ({
    ...common,
    face: 'top',
    route_mode: 'point',
    start_u: Number(dimensions.length) / 2,
    start_v: Number(dimensions.width) / 2,
    path_angle: 0,
    travel_length: 0,
    cutter_diameter: Math.min(8, Number(dimensions.width)),
    cutter_profile: 'straight',
    cutter_angle: 0,
    depth: Math.min(10, Math.max(Number(dimensions.thickness) / 2, 0.1)),
});

export const normalizePlungeRoute = (operation, part) => ({
    ...operation,
    face: operation.face ?? 'top',
    route_mode: operation.route_mode ?? 'point',
    start_u: Number(operation.start_u ?? part.dimensions.length / 2),
    start_v: Number(operation.start_v ?? part.dimensions.width / 2),
    path_angle: Number(operation.path_angle ?? 0),
    travel_length: Number(operation.travel_length ?? 0),
    cutter_diameter: Number(operation.cutter_diameter ?? 8),
    cutter_profile: operation.cutter_profile ?? 'straight',
    cutter_angle: Number(operation.cutter_angle ?? 0),
    depth: Number(operation.depth ?? Math.min(10, part.dimensions.thickness)),
});

export const plungeProfileName = (profile) => ({
    straight: 'прямая',
    dovetail: 'ласточкин хвост',
    v_groove: 'V-образная',
}[profile] ?? 'прямая');

export const fitPlungeOperationToFace = (operation, face, dimensions) => {
    const surface = surfaceDimensions(face, dimensions);
    const cutterDiameter = Math.min(Number(operation.cutter_diameter), surface.u, surface.v);
    const radius = plungeOperationRadius({ ...operation, cutter_diameter: cutterDiameter });
    const travelLength = operation.route_mode === 'path'
        ? Math.min(Number(operation.travel_length), Math.max(surface.u - cutterDiameter, 0.1))
        : 0;

    return {
        face,
        start_u: Math.max(surface.u / 2 - travelLength / 2, radius),
        start_v: surface.v / 2,
        path_angle: 0,
        travel_length: travelLength,
        cutter_diameter: cutterDiameter,
        depth: Math.min(Number(operation.depth), surface.depth),
    };
};
