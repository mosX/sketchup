export const normalizeGroove = (operation, part) => {
    if ('center_u' in operation && 'center_v' in operation && 'path_angle' in operation && 'groove_length' in operation) {
        return {
            blade_diameter: 190,
            face: 'top',
            ...operation,
            center_u: Number(operation.center_u),
            center_v: Number(operation.center_v),
            path_angle: Number(operation.path_angle),
            groove_length: Number(operation.groove_length),
            width: Number(operation.width),
            depth: Number(operation.depth),
        };
    }

    const direction = operation.direction ?? 'length';
    const start = Number(operation.start ?? 0);
    const end = Number(operation.end ?? part.dimensions.length);
    const offset = Number(operation.offset ?? part.dimensions.width / 2);

    return {
        id: operation.id,
        type: 'groove',
        status: operation.status,
        enabled: operation.enabled,
        face: operation.face ?? 'top',
        center_u: direction === 'width' ? offset : (start + end) / 2,
        center_v: direction === 'width' ? (start + end) / 2 : offset,
        path_angle: direction === 'width' ? 90 : 0,
        groove_length: Math.max(end - start, 0.1),
        width: Number(operation.width),
        depth: Number(operation.depth),
        blade_diameter: Number(operation.blade_diameter ?? 190),
    };
};

export const createGrooveOperation = (common, dimensions) => {
    const length = Number(dimensions.length);
    const width = Number(dimensions.width);
    const thickness = Number(dimensions.thickness);
    const grooveWidth = Math.min(3.2, Math.max(width / 2, 0.1));

    return {
        ...common,
        face: 'top',
        center_u: length / 2,
        center_v: width / 2,
        path_angle: 0,
        groove_length: Math.max(Math.round(length * 0.8), 0.1),
        width: grooveWidth,
        depth: Math.min(5, Math.max(thickness / 3, 0.1)),
        blade_diameter: 190,
    };
};
