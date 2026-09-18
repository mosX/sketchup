// Dependency-free because this factory also runs inside the script worker.
export function createScriptTransforms() {
    const identity = () => [[1, 0, 0], [0, 1, 0], [0, 0, 1]];
    const vector = (value) => {
        if (!Array.isArray(value) || value.length !== 3 || !value.every(Number.isFinite)) throw new Error('Expected three finite numbers: [x, y, z].');
        return [...value];
    };
    const add = (a, b) => a.map((v, i) => v + b[i]);
    const scale = (a, amount) => a.map(v => v * amount);
    const dot = (a, b) => a.reduce((sum, v, i) => sum + v * b[i], 0);
    const cross = (a, b) => [a[1] * b[2] - a[2] * b[1], a[2] * b[0] - a[0] * b[2], a[0] * b[1] - a[1] * b[0]];
    const transpose = a => a[0].map((_, i) => a.map(row => row[i]));
    const apply = (a, v) => a.map(row => dot(row, v));
    const multiply = (a, b) => a.map(row => transpose(b).map(column => dot(row, column)));
    const rotation = (angles) => {
        const [x, y, z] = vector(angles).map(v => v * Math.PI / 180);
        const rx = [[1, 0, 0], [0, Math.cos(x), -Math.sin(x)], [0, Math.sin(x), Math.cos(x)]];
        const ry = [[Math.cos(y), 0, Math.sin(y)], [0, 1, 0], [-Math.sin(y), 0, Math.cos(y)]];
        const rz = [[Math.cos(z), -Math.sin(z), 0], [Math.sin(z), Math.cos(z), 0], [0, 0, 1]];
        return multiply(multiply(rz, ry), rx);
    };
    const reflection = (axis) => {
        const index = ['x', 'y', 'z'].indexOf(axis);
        if (index < 0) throw new Error('Axis must be x, y or z.');
        const matrix = identity();
        matrix[index][index] = -1;
        return matrix;
    };
    const decompose = (matrix) => {
        const mirrored = dot(matrix[0], cross(matrix[1], matrix[2])) < 0;
        const r = mirrored ? multiply(matrix, reflection('x')) : matrix;
        const y = Math.asin(Math.max(-1, Math.min(1, -r[2][0])));
        const regular = Math.abs(Math.cos(y)) > 1e-7;
        const x = regular ? Math.atan2(r[2][1], r[2][2]) : 0;
        const z = regular ? Math.atan2(r[1][0], r[0][0]) : Math.atan2(-r[0][1], r[1][1]);
        return { rotation: [x, y, z].map(v => Math.round(v * 180 / Math.PI * 1e6) / 1e6), mirrored };
    };
    const align = (from, to) => {
        const cosine = Math.max(-1, Math.min(1, dot(from, to)));
        if (cosine > 1 - 1e-10) return identity();
        let axis = cross(from, to);
        if (cosine < -1 + 1e-10) axis = cross(from, Math.abs(from[0]) < 0.9 ? [1, 0, 0] : [0, 1, 0]);
        axis = scale(axis, 1 / Math.hypot(...axis));
        const sine = Math.sqrt(Math.max(0, 1 - cosine * cosine));
        const skew = [[0, -axis[2], axis[1]], [axis[2], 0, -axis[0]], [-axis[1], axis[0], 0]];
        return identity().map((row, i) => row.map((value, j) => value * cosine + axis[i] * axis[j] * (1 - cosine) + skew[i][j] * sine));
    };
    const face = (dimensions, name) => {
        const [length, width, thickness] = [dimensions.length, dimensions.width, dimensions.thickness];
        const faces = {
            start: { point: [-length / 2, 0, 0], normal: [-1, 0, 0] }, end: { point: [length / 2, 0, 0], normal: [1, 0, 0] },
            left: { point: [0, -width / 2, 0], normal: [0, -1, 0] }, right: { point: [0, width / 2, 0], normal: [0, 1, 0] },
            bottom: { point: [0, 0, -thickness / 2], normal: [0, 0, -1] }, top: { point: [0, 0, thickness / 2], normal: [0, 0, 1] },
        };
        if (!faces[name]) throw new Error(`Unknown face: ${name}`);
        return faces[name];
    };
    return { identity, vector, add, scale, apply, multiply, transpose, rotation, reflection, decompose, align, face };
}
