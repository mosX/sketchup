import { normalizeOperations } from './operations/catalog.js';
import { createScriptTransforms } from './scriptTransforms.js';
import { surfaceDimensions } from './operations/shared.js';

const { rotation } = createScriptTransforms();
const axes = ['x', 'y', 'z'];
const dimensions = ['length', 'width', 'thickness'];
const dimensionLimits = [100000, 100000, 10000];
const round = value => Math.round(value * 100) / 100;
const operationMeasurements = {
    cross_cut: ['position', 'kerf', 'cut_depth'],
    rip_cut: ['start_offset', 'end_offset', 'kerf', 'cut_depth'],
    groove: ['center_u', 'center_v', 'groove_length', 'width', 'depth', 'blade_diameter'],
    edge_roundover: ['radius'],
    plunge_route: ['start_u', 'start_v', 'travel_length', 'cutter_diameter', 'depth'],
    drill: ['center_u', 'center_v', 'diameter', 'depth'],
};

export const scaleFactorFromPointer = (start, current, pivot, axisConstrained = false) => {
    const startDistance = Math.hypot(start.x - pivot.x, start.y - pivot.y);
    if (!axisConstrained && startDistance >= 24) {
        return Math.max(0.01, Math.hypot(current.x - pivot.x, current.y - pivot.y) / startDistance);
    }

    const horizontal = current.x - start.x;
    const vertical = start.y - current.y;
    const delta = Math.abs(horizontal) >= Math.abs(vertical) ? horizontal : vertical;
    return Math.exp(delta / 180);
};

export const scaleAssemblyPose = ({ part, instance }, pivot, axis, factor) => {
    if (!Number.isFinite(factor) || factor <= 0) throw new Error('Укажите положительный коэффициент масштаба.');

    const factors = [factor, factor, factor];
    if (axis && factor !== 1) {
        const row = rotation(axes.map(key => Number(instance.rotation[key])))[axes.indexOf(axis)];
        const localAxis = row.findIndex(value => Math.abs(value) > 1 - 1e-10);
        if (localAxis < 0) throw new Error('Эта ось перекосит повёрнутую деталь. Используйте общий масштаб.');
        factors.fill(1);
        factors[localAxis] = factor;
    }
    const size = dimensions.map((key, i) => round(Number(part.dimensions[key]) * factors[i]));
    if (size.some((value, i) => value < 1 || value > dimensionLimits[i])) {
        throw new Error('Полученные размеры заготовки выходят за допустимые пределы.');
    }
    const position = Object.fromEntries(axes.map(key => [key, axis && axis !== key
        ? Number(instance.position[key])
        : round(pivot[key] + (Number(instance.position[key]) - pivot[key]) * factor)]));

    if (Object.values(position).some(value => Math.abs(value) > 100000)) {
        throw new Error('Положение выходит за границы сцены.');
    }
    return { factors, size, position };
};

const resizeOperation = (operation, factors, before, after) => {
    const [length, width, thickness] = factors;
    const measure = (field, factor) => {
        if (operation[field] != null) operation[field] = Number((Number(operation[field]) * factor).toFixed(6));
    };
    if (length === width && width === thickness) {
        for (const field of operationMeasurements[operation.type] ?? []) measure(field, length);
        return operation;
    }

    if (operation.type === 'cross_cut' || operation.type === 'rip_cut') {
        measure('cut_depth', thickness);
        if (operation.type === 'cross_cut') {
            measure('position', length);
            const angle = (value, factor) => Math.atan(Math.tan(Number(value) * Math.PI / 180) * factor) * 180 / Math.PI;
            operation.miter_angle = angle(operation.miter_angle, length / width);
            operation.bevel_angle = angle(operation.bevel_angle, length / thickness);
        } else {
            const offsetDelta = Number(operation.end_offset) - Number(operation.start_offset);
            const originalRatio = Math.hypot(Number(before.length), offsetDelta);
            const scaledRatio = Math.hypot(Number(after.length), offsetDelta * width);
            const horizontalNormalScale = originalRatio / scaledRatio * length * width;
            operation.bevel_angle = Math.atan(Math.tan(Number(operation.bevel_angle) * Math.PI / 180) * horizontalNormalScale / thickness) * 180 / Math.PI;
            measure('start_offset', width);
            measure('end_offset', width);
        }
        return operation;
    }

    if (['drill', 'groove', 'plunge_route'].includes(operation.type)) {
        const original = surfaceDimensions(operation.face, before);
        const resized = surfaceDimensions(operation.face, after);
        const u = resized.u / original.u;
        const v = resized.v / original.v;
        const normal = resized.depth / original.depth;
        for (const field of ['center_u', 'start_u']) measure(field, u);
        for (const field of ['center_v', 'start_v']) measure(field, v);
        measure('depth', normal);
        if (operation.through) operation.depth = resized.depth;
        if (operation.path_angle != null && operation.route_mode !== 'point') {
            const radians = Number(operation.path_angle) * Math.PI / 180;
            const x = Math.cos(radians) * u;
            const y = Math.sin(radians) * v;
            operation.path_angle = Math.atan2(y, x) * 180 / Math.PI;
            measure('groove_length', Math.hypot(x, y));
            measure('travel_length', Math.hypot(x, y));
        }
    }
    // Tool diameters, saw kerf and roundover radii stay manufacturable; API validates their fit.
    return operation;
};

export const planAssemblyScale = (members, pivot, axis, factor) => {
    const variants = new Map();
    const bindings = [];
    for (const member of members) {
        const { part, instance } = member;
        const { factors, size, position } = scaleAssemblyPose(member, pivot, axis, factor);
        const key = [part.id, ...factors].join(':');
        if (!variants.has(key)) {
            const resized = Object.fromEntries(dimensions.map((key, i) => [key, size[i]]));
            const operations = normalizeOperations(part).map(operation => {
                if (!Object.hasOwn(operationMeasurements, operation.type)) throw new Error('У заготовки есть операция, которую нельзя масштабировать.');
                return resizeOperation(operation, factors, part.dimensions, resized);
            });
            variants.set(key, {
                type: 'create_part',
                temporary_id: `scale-${variants.size + 1}`,
                data: {
                    name: `${part.name.slice(0, 240)} (масштаб)`,
                    material: part.material,
                    grain_axis: part.grain_axis,
                    ...resized,
                    operations,
                },
            });
        }
        bindings.push({
            id: instance.id,
            originalPartId: part.id,
            originalPosition: { ...instance.position },
            partRef: variants.get(key).temporary_id,
            position,
        });
    }
    return { variants: [...variants.values()], bindings };
};
