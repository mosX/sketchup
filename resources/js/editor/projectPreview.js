import { assemblyInstanceState } from './assemblyTree.js';
import { createScriptTransforms } from './scriptTransforms.js';

const { add, apply, rotation } = createScriptTransforms();
const corners = [
    [-1, -1, -1], [1, -1, -1], [1, 1, -1], [-1, 1, -1],
    [-1, -1, 1], [1, -1, 1], [1, 1, 1], [-1, 1, 1],
];
const sides = [
    [[0, 3, 7, 4], [-1, 0, 0]], [[1, 2, 6, 5], [1, 0, 0]],
    [[0, 1, 5, 4], [0, -1, 0]], [[3, 2, 6, 7], [0, 1, 0]],
    [[0, 1, 2, 3], [0, 0, -1]], [[4, 5, 6, 7], [0, 0, 1]],
];
const front = [0.5, -Math.sqrt(0.75), 0];
const right = [Math.sqrt(0.75), 0.5, 0];
const elevation = Math.PI / 6;
const depth = [front[0] * Math.cos(elevation), front[1] * Math.cos(elevation), Math.sin(elevation)];
const down = [front[0] * Math.sin(elevation), front[1] * Math.sin(elevation), -Math.cos(elevation)];
const dot = (a, b) => a.reduce((sum, value, index) => sum + value * b[index], 0);
const xyz = value => ['x', 'y', 'z'].map(axis => Number(value[axis]));

export const projectPreviewFaces = ({ parts = [], assembly_groups: groups = [] }) => {
    const visibleIds = new Set(assemblyInstanceState(groups, parts).visibleInstanceIds);
    const faces = [];

    for (const part of parts) {
        const half = ['length', 'width', 'thickness'].map(axis => Number(part.dimensions[axis]) / 2);
        for (const instance of part.instances ?? []) {
            if (!visibleIds.has(instance.id)) continue;

            const center = xyz(instance.position);
            const orientation = rotation(xyz(instance.rotation));
            const transform = vector => apply(orientation, [instance.mirrored ? -vector[0] : vector[0], vector[1], vector[2]]);
            const vertices = corners.map(corner => add(center, transform(corner.map((sign, axis) => sign * half[axis]))));
            for (const [indices, normal] of sides) {
                const worldNormal = transform(normal);
                if (dot(worldNormal, depth) <= 1e-6) continue;

                const points = indices.map(index => vertices[index]);
                faces.push({
                    points: points.map(point => [dot(point, right), dot(point, down)]),
                    depth: points.reduce((sum, point) => sum + dot(point, depth), 0) / points.length,
                    color: `hsl(31 43% ${48 + 25 * Math.max(0, dot(worldNormal, [-0.3, -0.55, 0.78])) + (part.id % 4) * 1.5}%)`,
                });
            }
        }
    }

    return faces.sort((a, b) => a.depth - b.depth);
};

export const drawProjectPreview = (canvas, snapshot) => {
    const context = canvas.getContext('2d');
    if (!context) throw new Error('Canvas unavailable');

    const faces = projectPreviewFaces(snapshot);
    const { width, height } = canvas;
    context.clearRect(0, 0, width, height);
    if (!faces.length) return false;

    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    for (const { points } of faces) {
        for (const [x, y] of points) {
            minX = Math.min(minX, x);
            minY = Math.min(minY, y);
            maxX = Math.max(maxX, x);
            maxY = Math.max(maxY, y);
        }
    }
    const scale = Math.min(width * 0.76 / (maxX - minX), height * 0.77 / (maxY - minY));
    const offsetX = (width - (maxX + minX) * scale) / 2;
    const offsetY = (height - (maxY + minY) * scale) / 2 + height * 0.025;

    context.lineWidth = 1.4;
    context.lineJoin = 'round';
    context.strokeStyle = '#66462480';
    for (const { points, color } of faces) {
        context.beginPath();
        points.forEach(([x, y], index) => {
            const method = index === 0 ? 'moveTo' : 'lineTo';
            context[method](x * scale + offsetX, y * scale + offsetY);
        });
        context.closePath();
        context.fillStyle = color;
        context.fill();
        context.stroke();
    }

    return true;
};
