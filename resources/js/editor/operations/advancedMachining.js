import { contourPresets, isSimplePath, roundContourPoint } from './shaping.js';
import { faceLabel, surfaceDimensions } from './shared.js';

export const advancedTools = {
    drill_row: { icon: '⠿', caption: 'Кондуктор', label: 'Сверлильный кондуктор' },
    pocket_screw: { icon: '⤡', caption: 'Косой шуруп', label: 'Сверление под косой шуруп' },
    curve_route: { icon: '〰', caption: 'Фреза по кривой', label: 'Фрезер по кривой' },
    taper: { icon: '◿', caption: 'Сужение', label: 'Сужение ножки' },
    turning: { icon: '⧖', caption: 'Токарный', label: 'Токарная обработка' },
};

export const isFaceMachining = type => ['drill_row', 'pocket_screw', 'curve_route'].includes(type);
export const isPathMachining = type => ['jigsaw_cut', 'curve_route'].includes(type);
export const isRetainedShape = type => ['taper', 'turning'].includes(type);
export const machiningFlag = value => value === true || value === 1 || value === '1';
const radians = degrees => Number(degrees) * Math.PI / 180;
const fit = (value, min, max) => Math.min(Math.max(Number(value) || 0, min), Math.max(min, max));

export const drillRowCenters = (operation) => {
    const angle = radians(operation.path_angle);
    return Array.from({ length: Number(operation.hole_count) }, (_, index) => ({
        u: Number(operation.center_u) + Math.cos(angle) * Number(operation.hole_spacing) * index,
        v: Number(operation.center_v) + Math.sin(angle) * Number(operation.hole_spacing) * index,
    }));
};

const fitRow = (operation, face) => {
    const angle = radians(operation.path_angle);
    const c = Math.cos(angle), s = Math.sin(angle);
    const diameter = fit(operation.diameter, 0.1, Math.min(face.u, face.v) * 0.9);
    const maxSpan = Math.min(Math.abs(c) < 1e-8 ? Infinity : (face.u - diameter) / Math.abs(c),
        Math.abs(s) < 1e-8 ? Infinity : (face.v - diameter) / Math.abs(s));
    const minStep = diameter + 0.05;
    const count = Math.floor(fit(operation.hole_count, 1, Math.min(40, Math.floor(maxSpan / minStep) + 1)));
    const spacing = fit(operation.hole_spacing, minStep, count > 1 ? maxSpan / (count - 1) : Math.max(face.u, face.v));
    const du = c * spacing * (count - 1), dv = s * spacing * (count - 1);
    const radius = diameter / 2;
    return {
        ...operation, diameter, hole_count: count, hole_spacing: spacing,
        center_u: fit(operation.center_u, radius - Math.min(du, 0), face.u - radius - Math.max(du, 0)),
        center_v: fit(operation.center_v, radius - Math.min(dv, 0), face.v - radius - Math.max(dv, 0)),
        depth: operation.through ? face.depth : fit(operation.depth, 0.1, face.depth),
    };
};

export const fitAdvanced = (operation, dimensions) => {
    if (operation.type === 'taper') return {
        ...operation,
        start_width: fit(operation.start_width, 0.1, dimensions.width),
        end_width: fit(operation.end_width, 0.1, dimensions.width),
        start_height: fit(operation.start_height, 0.1, dimensions.thickness),
        end_height: fit(operation.end_height, 0.1, dimensions.thickness),
    };
    if (!isFaceMachining(operation.type)) return operation;
    const face = surfaceDimensions(operation.face, dimensions);
    if (operation.type === 'drill_row') return fitRow(operation, face);
    if (operation.type === 'pocket_screw') {
        const angle = radians(operation.entry_angle);
        const tilt = radians(operation.path_angle);
        const uRatio = Math.hypot(Math.cos(tilt) / Math.sin(angle), Math.sin(tilt));
        const vRatio = Math.hypot(Math.sin(tilt) / Math.sin(angle), Math.cos(tilt));
        const diameter = fit(operation.diameter, 0.2, Math.min(face.u / uRatio * 0.95, face.v / vRatio * 0.95, face.depth * 0.7));
        const uMargin = diameter / 2 * uRatio, vMargin = diameter / 2 * vRatio;
        const depth = fit(operation.depth, 0.1, face.depth / Math.sin(angle));
        return {
            ...operation, diameter, depth,
            pilot_diameter: fit(operation.pilot_diameter, 0.1, diameter - 0.1),
            pilot_length: fit(operation.pilot_length, 0.1, Math.max(face.u, face.v, face.depth) * 2),
            center_u: fit(operation.center_u, Math.min(uMargin, face.u / 2), Math.max(face.u - uMargin, face.u / 2)),
            center_v: fit(operation.center_v, Math.min(vMargin, face.v / 2), Math.max(face.v - vMargin, face.v / 2)),
        };
    }
    return { ...operation, depth: fit(operation.depth, 0.1, face.depth - 0.01),
        cutter_diameter: fit(operation.cutter_diameter, 0.1, Math.min(face.u, face.v)) };
};

export const routePresets = (face, dimensions, closed) => {
    if (closed) return contourPresets(face, dimensions).filter((_, index) => index % 2 === 0);
    const { u, v } = surfaceDimensions(face, dimensions);
    return [[0.2, 0.35], [0.4, 0.65], [0.62, 0.65], [0.8, 0.35]].map(([x, y]) => roundContourPoint({ u: x * u, v: y * v }));
};

export const turningPresets = (dimensions, shaped = true) => {
    const length = Number(dimensions.length), max = Math.min(dimensions.width, dimensions.thickness);
    const stations = shaped ? [[0, 0.75], [0.14, 0.9], [0.32, 0.52], [0.55, 0.75], [0.78, 0.42], [1, 0.35]] : [[0, 0.8], [1, 0.8]];
    return stations.map(([at, diameter]) => ({ at: at * length, diameter: diameter * max }));
};

export const createAdvancedOperation = (common, dimensions) => {
    const length = Number(dimensions.length), width = Number(dimensions.width), thickness = Number(dimensions.thickness);
    const defaults = {
        drill_row: {
            face: 'top', center_u: length * 0.2, center_v: width / 2, path_angle: 0,
            diameter: Math.min(5, width * 0.2, length * 0.1), depth: Math.min(10, thickness / 2),
            through: false, hole_count: 5, hole_spacing: Math.min(32, length * 0.15),
        },
        pocket_screw: {
            face: 'top', center_u: length * 0.65, center_v: width / 2, path_angle: 0, entry_angle: 15,
            diameter: Math.min(9, width * 0.22, length * 0.1, thickness * 0.6),
            depth: Math.min(24, thickness), pilot_diameter: 4, pilot_length: Math.min(45, length * 0.3),
        },
        curve_route: {
            face: 'top', points: routePresets('top', dimensions, false), closed_path: false, smooth_path: true,
            cutter_diameter: Math.min(8, length * 0.12, width * 0.12), cutter_profile: 'straight',
            cutter_angle: 0, depth: Math.min(thickness * 0.3, 4),
        },
        taper: { taper_sides: 'four', start_width: width, start_height: thickness, end_width: width * 0.55, end_height: thickness * 0.55 },
        turning: { stations: turningPresets(dimensions), smooth_profile: true },
    };
    return fitAdvanced({ ...common, ...defaults[common.type] }, dimensions);
};

export const routeEndRadii = (operation) => {
    const radius = Number(operation.cutter_diameter) / 2;
    const depth = Number(operation.depth);
    if (operation.cutter_profile === 'dovetail') return [radius - depth * Math.tan(radians(operation.cutter_angle)), radius];
    if (operation.cutter_profile === 'v_groove') return [depth * Math.tan(radians(operation.cutter_angle / 2)), 0.005];
    return [radius, radius];
};

export const validRoute = (operation, dimensions) => {
    const points = operation.points;
    const face = surfaceDimensions(operation.face, dimensions);
    const [top, bottom] = routeEndRadii(operation);
    const radius = Math.max(top, bottom);
    const closed = machiningFlag(operation.closed_path);
    if (!Array.isArray(points) || points.length < (closed ? 3 : 2) || points.length > 32
        || Number(operation.depth) <= 0 || Number(operation.depth) >= face.depth || Math.min(top, bottom) <= 0
        || top > Number(operation.cutter_diameter) / 2 + 1e-7
        || points.some(({ u, v }) => !Number.isFinite(Number(u)) || !Number.isFinite(Number(v))
            || u < radius - 1e-7 || v < radius - 1e-7 || u > face.u - radius + 1e-7 || v > face.v - radius + 1e-7)) return false;
    const edges = points.slice(1).map((point, index) => Math.hypot(point.u - points[index].u, point.v - points[index].v));
    if (edges.some(size => size < 0.01)) return false;
    return isSimplePath(points, closed, 32);
};

export const normalizeAdvanced = operation => {
    const fields = operation.type === 'curve_route' ? ['closed_path', 'smooth_path']
        : operation.type === 'turning' ? ['smooth_profile'] : operation.type === 'drill_row' ? ['through'] : [];
    return { ...operation, ...Object.fromEntries(fields.map(field => [field, machiningFlag(operation[field])])) };
};

export const resizeAdvanced = (operation, before, after) => {
    const factor = key => Number(after[key]) / Number(before[key]);
    const resize = (key, scale) => {
        if (operation[key] != null) operation[key] = Number(operation[key]) * scale;
    };
    if (operation.type === 'turning') {
        operation.stations = operation.stations.map(({ at, diameter }, index, stations) => ({
            at: index === stations.length - 1 ? Number(after.length) : Number(at) * factor('length'),
            diameter: Number(diameter) * Math.min(factor('width'), factor('thickness')),
        }));
        return operation;
    }
    if (operation.type === 'taper') {
        for (const key of ['start_width', 'end_width']) resize(key, factor('width'));
        for (const key of ['start_height', 'end_height']) resize(key, factor('thickness'));
        return operation;
    }
    const source = surfaceDimensions(operation.face, before), target = surfaceDimensions(operation.face, after);
    const u = target.u / source.u, v = target.v / source.v, normal = target.depth / source.depth;
    const tangent = Math.min(u, v);
    if (operation.type === 'curve_route') {
        operation.points = operation.points.map(point => ({ u: Number(point.u) * u, v: Number(point.v) * v }));
        resize('depth', normal);
        resize('cutter_diameter', tangent);
        if (operation.cutter_profile !== 'straight') {
            const multiplier = operation.cutter_profile === 'v_groove' ? 2 : 1;
            const original = radians(operation.cutter_angle) / multiplier;
            operation.cutter_angle = Math.atan(Math.tan(original) * tangent / normal) * 180 / Math.PI * multiplier;
        }
        return operation;
    }
    const heading = radians(operation.path_angle);
    const x = Math.cos(heading) * u, y = Math.sin(heading) * v;
    operation.path_angle = Math.atan2(y, x) * 180 / Math.PI;
    resize('center_u', u);
    resize('center_v', v);
    if (operation.type === 'drill_row') {
        resize('hole_spacing', Math.hypot(x, y));
        resize('diameter', tangent);
        resize('depth', normal);
        if (machiningFlag(operation.through)) operation.depth = target.depth;
    } else {
        const entry = radians(operation.entry_angle);
        const inPlane = Math.hypot(x, y) * Math.cos(entry);
        const inward = Math.sin(entry) * normal;
        operation.entry_angle = Math.atan2(inward, inPlane) * 180 / Math.PI;
        const axial = Math.hypot(inPlane, inward);
        resize('depth', axial);
        resize('pilot_length', axial);
        for (const key of ['diameter', 'pilot_diameter']) resize(key, Math.min(tangent, normal));
    }
    return operation;
};

const display = value => Number(Number(value).toFixed(2));
export const advancedSummary = (operation) => {
    const { type, face, diameter, depth } = operation;
    const center = `${faceLabel(face)} · U ${display(operation.center_u)}, V ${display(operation.center_v)}`;
    if (type === 'drill_row') return `${center} · ${operation.hole_count} × Ø${display(diameter)} · шаг ${display(operation.hole_spacing)} мм · ${operation.path_angle}° · ${operation.through ? 'насквозь' : `глубина ${display(depth)} мм`}`;
    if (type === 'pocket_screw') return `${center} · ${operation.entry_angle}° к грани, направление ${operation.path_angle}° · ступень Ø${display(diameter)} × ${display(depth)} мм · пилот Ø${display(operation.pilot_diameter)} × ${display(operation.pilot_length)} мм`;
    if (type === 'curve_route') return `${faceLabel(face)} · ${operation.closed_path ? 'замкнутая' : 'открытая'} траектория · ${operation.points.length} точек · Ø${display(operation.cutter_diameter)} × ${display(depth)} мм`;
    if (type === 'taper') return `${operation.taper_sides === 'two' ? 'Две стороны' : 'Четыре стороны'} · ${display(operation.start_width)}${operation.taper_sides === 'four' ? ` × ${display(operation.start_height)}` : ''} → ${display(operation.end_width)}${operation.taper_sides === 'four' ? ` × ${display(operation.end_height)}` : ''} мм`;
    return operation.stations.map(({ at, diameter }) => `${display(at)} мм / Ø${display(diameter)}`).join(' · ');
};
