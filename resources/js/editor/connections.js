import { surfaceDimensions } from './operations/shared.js';

const centerFor = (parameters, role, surface) => ({
    u: Number(parameters[`${role}_center_u`] ?? surface.u / 2),
    v: Number(parameters[`${role}_center_v`] ?? surface.v / 2),
});

const operation = (type) => ({ type, status: 'draft', enabled: true });

const groove = (face, center, length, width, depth, angle = 0) => ({
    ...operation('groove'),
    face,
    center_u: center.u,
    center_v: center.v,
    path_angle: angle,
    groove_length: Math.max(Number(length), 0.1),
    width: Math.max(Number(width), 0.1),
    depth: Math.max(Number(depth), 0.1),
    blade_diameter: 190,
});

const dowels = (face, surface, parameters, role) => {
    const diameter = Number(parameters.dowel_diameter ?? 8);
    const count = Number(parameters.dowel_count ?? 2);
    const spacing = Number(parameters.dowel_spacing ?? diameter * 2);
    const depth = Math.min(Number(parameters.dowel_depth ?? 25), surface.depth);
    const center = centerFor(parameters, role, surface);
    const angle = Number(parameters.joint_angle ?? 0) * Math.PI / 180;
    const halfSpan = spacing * (count - 1) / 2;

    return Array.from({ length: count }, (_, index) => {
        const offset = count === 1 ? 0 : -halfSpan + spacing * index;

        return {
            ...operation('drill'),
            face,
            center_u: center.u + Math.cos(angle) * offset,
            center_v: center.v + Math.sin(angle) * offset,
            diameter,
            depth,
            through: false,
        };
    });
};

const tenonWaste = (face, surface, center, width, thickness, depth) => {
    const left = center.u - width / 2;
    const right = surface.u - (center.u + width / 2);
    const bottom = center.v - thickness / 2;
    const top = surface.v - (center.v + thickness / 2);

    return [
        left >= 0.1 ? groove(face, { u: left / 2, v: surface.v / 2 }, left, surface.v, depth) : null,
        right >= 0.1 ? groove(face, { u: surface.u - right / 2, v: surface.v / 2 }, right, surface.v, depth) : null,
        bottom >= 0.1 ? groove(face, { u: center.u, v: bottom / 2 }, width, bottom, depth) : null,
        top >= 0.1 ? groove(face, { u: center.u, v: surface.v - top / 2 }, width, top, depth) : null,
    ].filter(Boolean);
};

export const connectionFacePlacement = (connection, part, role) => {
    const parameters = connection.parameters ?? {};
    const face = parameters[`${role}_face`] ?? (role === 'primary' ? 'end' : 'start');
    const surface = surfaceDimensions(face, part.dimensions);

    return { face, surface, center: centerFor(parameters, role, surface) };
};

export const connectionOperationPlan = (connection, parts) => {
    if (!connection || connection.type === 'butt') return [];

    const parameters = connection.parameters ?? {};
    const entries = ['primary', 'secondary'].map((role) => {
        const instanceId = connection[`${role}_instance_id`];
        const part = parts.find((candidate) => candidate.instances?.some((instance) => instance.id === instanceId));
        const instance = part?.instances?.find((candidate) => candidate.id === instanceId);

        return part && instance ? { role, part, instance, ...connectionFacePlacement(connection, part, role) } : null;
    }).filter(Boolean);

    if (entries.length !== 2) return [];

    const [primary, secondary] = entries;

    if (connection.type === 'dowel') {
        return entries.map((entry) => ({ ...entry, operations: dowels(entry.face, entry.surface, parameters, entry.role) }));
    }

    if (connection.type === 'half_lap') {
        const length = Number(parameters.joint_length ?? Math.min(primary.surface.u, secondary.surface.u));
        const width = Number(parameters.joint_width ?? Math.min(primary.surface.v, secondary.surface.v));
        const angle = Number(parameters.joint_angle ?? 0);
        const ratio = Number(parameters.depth_ratio ?? 0.5);

        return [
            { ...primary, operations: [groove(primary.face, primary.center, length, width, primary.surface.depth * ratio, angle)] },
            { ...secondary, operations: [groove(secondary.face, secondary.center, length, width, secondary.surface.depth * (1 - ratio), angle)] },
        ];
    }

    const tenonWidth = Number(parameters.tenon_width ?? 30);
    const tenonThickness = Number(parameters.tenon_thickness ?? 10);
    const tenonLength = Number(parameters.tenon_length ?? 20);

    return [
        { ...primary, operations: tenonWaste(primary.face, primary.surface, primary.center, tenonWidth, tenonThickness, tenonLength) },
        { ...secondary, operations: [groove(secondary.face, secondary.center, tenonWidth, tenonThickness, tenonLength)] },
    ];
};
