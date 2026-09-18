import { createScriptTransforms } from './scriptTransforms.js';

const math = createScriptTransforms();
const axes = ['x', 'y', 'z'];
const vector = value => axes.map(axis => Number(value[axis]));
const object = values => Object.fromEntries(axes.map((axis, i) => {
    if (!Number.isFinite(values[i])) throw new Error('Координаты должны быть конечными числами.');
    const value = Math.round(values[i] * 1e6) / 1e6;
    return [axis, value === 0 ? 0 : value];
}));
const matrix = instance => math.multiply(math.rotation(vector(instance.rotation)), instance.mirrored ? math.reflection('x') : math.identity());

export const stockBounds = ({ part, instance }) => {
    const rotation = matrix(instance);
    const size = [part.dimensions.length, part.dimensions.width, part.dimensions.thickness].map(Number);
    const half = rotation.map(row => row.reduce((sum, value, i) => sum + Math.abs(value) * size[i] / 2, 0));
    const center = vector(instance.position);
    return { center, half, min: center.map((v, i) => v - half[i]), max: center.map((v, i) => v + half[i]) };
};

// Uses the original stock's planar faces, not faces created by machining.
export const placeAssemblyPart = (source, target, options, secondTarget = null) => {
    const gap = Number(options.gap ?? 0);
    if (!Number.isFinite(gap)) throw new Error('Введите конечное значение зазора.');
    if (!source || !target || source.instance.id === target.instance.id) throw new Error('Выберите другую опорную деталь.');
    let position = vector(source.instance.position);
    let rotation = { ...source.instance.rotation };
    if (options.mode === 'faces') {
        const from = math.face(source.part.dimensions, options.face);
        const to = math.face(target.part.dimensions, options.targetFace);
        const sourceMatrix = matrix(source.instance), targetMatrix = matrix(target.instance);
        const normal = math.apply(targetMatrix, to.normal);
        const aligned = math.multiply(math.align(math.apply(sourceMatrix, from.normal), math.scale(normal, -1)), sourceMatrix);
        const destination = math.add(math.add(vector(target.instance.position), math.apply(targetMatrix, to.point)), math.scale(normal, gap));
        position = math.add(destination, math.scale(math.apply(aligned, from.point), -1));
        rotation = object(math.decompose(aligned).rotation);
    } else {
        const index = axes.indexOf(options.axis);
        if (index < 0) throw new Error('Выберите ось X, Y или Z.');
        const a = stockBounds(source), b = stockBounds(target);
        if (options.mode === 'center') position[index] = b.center[index] + gap;
        else if (options.mode === 'edge') {
            const side = options.side === 'min' ? 'min' : 'max';
            position[index] += b[side][index] - a[side][index] + gap;
        } else if (options.mode === 'between') {
            if (gap < 0) throw new Error('Минимальный зазор не может быть отрицательным.');
            if (!secondTarget || [source.instance.id, target.instance.id].includes(secondTarget.instance.id)) throw new Error('Выберите две разные опорные детали.');
            const c = stockBounds(secondTarget);
            const [left, right] = b.center[index] <= c.center[index] ? [b, c] : [c, b];
            if (right.min[index] - left.max[index] < 2 * a.half[index] + 2 * gap - 1e-6) throw new Error('Деталь не помещается между опорами с указанным минимальным зазором.');
            position[index] = (left.max[index] + right.min[index]) / 2;
        } else throw new Error('Неизвестный способ выравнивания.');
    }
    return { position: object(position), rotation };
};
