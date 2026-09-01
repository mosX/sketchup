import { createGrooveOperation, normalizeGroove } from './groove.js';
import { createDrillOperation, normalizeDrill } from './drill.js';
import { createPlungeRouteOperation, normalizePlungeRoute, plungeProfileName } from './plungeRoute.js';
import { createRoundoverOperation, roundoverEdgeLabels } from './roundover.js';
import { faceLabel, operationId } from './shared.js';

export const normalizeOperations = (part) => (part.operations ?? []).map((operation) => {
    if (operation.type !== 'angled_cut') {
        const normalized = { enabled: true, status: 'applied', ...operation, id: operation.id ?? operationId() };

        if (['cross_cut', 'rip_cut'].includes(operation.type)) {
            normalized.cut_depth = Number(operation.cut_depth ?? part.dimensions.thickness);
            normalized.cut_direction = operation.cut_direction ?? 'top_down';
        }

        if (operation.type === 'groove') return normalizeGroove(normalized, part);
        if (operation.type === 'edge_roundover') normalized.radius = Number(operation.radius);
        if (operation.type === 'plunge_route') return normalizePlungeRoute(normalized, part);
        if (operation.type === 'drill') return normalizeDrill(normalized, part);

        return normalized;
    }

    const angle = Number(operation.angle ?? 0);

    return {
        id: operationId(),
        type: 'cross_cut',
        status: 'applied',
        enabled: true,
        position: operation.end === 'start' ? 0 : Number(part.dimensions.length),
        miter_angle: operation.axis === 'width' ? angle : 0,
        bevel_angle: operation.axis === 'thickness' ? angle : 0,
        kerf: 0,
        cut_depth: Number(part.dimensions.thickness),
        cut_direction: 'top_down',
        keep_side: operation.end === 'start' ? 'end' : 'start',
    };
});

export const createOperation = (type, dimensions) => {
    const length = Number(dimensions.length);
    const width = Number(dimensions.width);
    const thickness = Number(dimensions.thickness);
    const common = { id: operationId(), type, status: 'draft', enabled: true };

    if (type === 'cross_cut') {
        return { ...common, position: Math.round(length * 0.9), miter_angle: 0, bevel_angle: 0, kerf: 3.2, cut_depth: thickness, cut_direction: 'top_down', keep_side: 'start' };
    }

    if (type === 'rip_cut') {
        return { ...common, reference_side: 'left', start_offset: Math.round(width * 0.25), end_offset: Math.round(width * 0.25), bevel_angle: 0, kerf: 3.2, cut_depth: thickness, cut_direction: 'top_down', keep_side: 'opposite' };
    }

    if (type === 'edge_roundover') return createRoundoverOperation(common, dimensions);
    if (type === 'plunge_route') return createPlungeRouteOperation(common, dimensions);
    if (type === 'drill') return createDrillOperation(common, dimensions);

    return createGrooveOperation(common, dimensions);
};

export const operationLabel = (operation) => ({
    cross_cut: 'Поперечный рез',
    rip_cut: 'Продольный рез',
    groove: 'Паз пилой',
    edge_roundover: 'Кромочный фрезер',
    plunge_route: 'Погружной фрезер',
    drill: 'Сверление',
}[operation.type] ?? 'Операция');

export const operationSummary = (operation) => {
    if (operation.type === 'cross_cut') return `${operation.position} мм · глубина ${operation.cut_depth} мм`;
    if (operation.type === 'rip_cut') return `${operation.start_offset} → ${operation.end_offset} мм · глубина ${operation.cut_depth} мм`;
    if (operation.type === 'edge_roundover') return `${roundoverEdgeLabels[operation.edge] ?? operation.edge} · R${operation.radius} мм`;
    if (operation.type === 'plunge_route') {
        const profile = plungeProfileName(operation.cutter_profile).replace('V-образная', 'V-фреза');
        return operation.route_mode === 'path'
            ? `${profile} · Ø${operation.cutter_diameter} × ${operation.depth} мм · проход ${operation.travel_length} мм под ${operation.path_angle}°`
            : `${profile} · Ø${operation.cutter_diameter} × ${operation.depth} мм · погружение`;
    }
    if (operation.type === 'drill') {
        const depth = operation.through ? 'сквозное' : `глубина ${operation.depth} мм`;
        return `Ø${operation.diameter} мм · ${depth} · ${faceLabel(operation.face)}`;
    }

    return `${operation.groove_length} × ${operation.width} × ${operation.depth} мм · ${operation.path_angle}°`;
};
