import test from 'node:test';
import assert from 'node:assert/strict';
import { placeAssemblyPart, stockBounds } from '../../resources/js/editor/assemblyPlacement.js';
import { createScriptTransforms } from '../../resources/js/editor/scriptTransforms.js';

const item = (id, position = {}, rotation = {}, mirrored = false) => ({ part: { dimensions: { length: 100, width: 40, thickness: 20 } }, instance: { id, position: { x: 0, y: 0, z: 0, ...position }, rotation: { x: 0, y: 0, z: 0, ...rotation }, mirrored } });
test('placing bottom on top uses Z and an exact face gap', () => {
    const result = placeAssemblyPart(item(1), item(2, { z: 100 }), { mode: 'faces', face: 'bottom', targetFace: 'top', gap: 3 });
    assert.deepEqual(result.position, { x: 0, y: 0, z: 123 });
    assert.deepEqual(result.rotation, { x: 0, y: 0, z: 0 });
});
test('rotated mirrored stock faces meet with opposing normals', () => {
    const a = item(1, {}, { x: 30, y: 25, z: 40 }, true), b = item(2, { x: 70, y: 60, z: 120 }, { x: 60, y: 30, z: 25 });
    const result = placeAssemblyPart(a, b, { mode: 'faces', face: 'start', targetFace: 'right', gap: 5 });
    const m = createScriptTransforms();
    const vec = o => [o.x, o.y, o.z];
    const ar = m.multiply(m.rotation(vec(result.rotation)), m.reflection('x')), br = m.rotation(vec(b.instance.rotation));
    const from = m.face(a.part.dimensions, 'start'), to = m.face(b.part.dimensions, 'right');
    const actual = m.add(vec(result.position), m.apply(ar, from.point));
    const expected = m.add(m.add(vec(b.instance.position), m.apply(br, to.point)), m.scale(m.apply(br, to.normal), 5));
    actual.forEach((v, i) => assert.ok(Math.abs(v - expected[i]) < 1e-5));
});
test('world bounds account for rotation; edge and center preserve other axes', () => {
    const a = item(1, { x: 12, y: 8 }), b = item(2, { x: 200 }, { z: 90 });
    assert.ok(Math.abs(stockBounds(b).half[0] - 20) < 1e-8);
    assert.equal(placeAssemblyPart(a, b, { mode: 'edge', axis: 'x', side: 'max', gap: 5 }).position.x, 175);
    assert.deepEqual(placeAssemblyPart(a, b, { mode: 'center', axis: 'z', gap: 4 }).position, { x: 12, y: 8, z: 4 });
});
test('between centers in clear space and rejects insufficient clearance', () => {
    const a = item(1), b = item(2, { x: -150 }), c = item(3, { x: 200 });
    assert.equal(placeAssemblyPart(a, b, { mode: 'between', axis: 'x', gap: 10 }, c).position.x, 25);
    assert.throws(() => placeAssemblyPart(a, b, { mode: 'between', axis: 'x', gap: 100 }, c), /не помещается/);
    assert.throws(() => placeAssemblyPart(a, b, { mode: 'between', axis: 'x' }, b), /разные/);
});
