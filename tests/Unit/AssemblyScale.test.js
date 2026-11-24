import test from 'node:test';
import assert from 'node:assert/strict';
import { planAssemblyScale, scaleAssemblyPose, scaleFactorFromPointer } from '../../resources/js/editor/assemblyScale.js';

const pivot = { x: 0, y: 0, z: 0 };

test('mouse scale follows radial distance, including downward-right movement', () => {
    assert.equal(scaleFactorFromPointer({ x: 30, y: 40 }, { x: 60, y: 80 }, pivot), 2);
    assert.equal(scaleFactorFromPointer({ x: 30, y: 40 }, { x: 15, y: 20 }, pivot), 0.5);
    assert.ok(scaleFactorFromPointer(pivot, { x: 40, y: 40 }, pivot) > 1);
});

test('axis-constrained mouse scale responds even at constant radial distance', () => {
    assert.ok(scaleFactorFromPointer({ x: -30, y: 40 }, { x: 30, y: 40 }, pivot, true) > 1);
});
const member = (id, position, rotation = pivot, operations = []) => ({
    part: { id: 5, name: 'Board', material: 'Pine', grain_axis: 'length', dimensions: { length: 200, width: 80, thickness: 20 }, operations },
    instance: { id, position, rotation },
});

test('uniform scaling shares a resized variant and scales drilling and group spacing', () => {
    const drill = { id: 'hole', type: 'drill', face: 'top', diameter: 6, center_u: 70, center_v: 30, depth: 8, through: false };
    const plan = planAssemblyScale([member(1, { x: -20, y: 0, z: 10 }, pivot, [drill]), member(2, { x: 20, y: 0, z: 10 }, pivot, [drill])], pivot, null, 1.5);
    assert.equal(plan.variants.length, 1);
    assert.equal(plan.variants[0].data.length, 300);
    assert.equal(plan.variants[0].data.operations[0].diameter, 9);
    assert.deepEqual(plan.bindings.map(binding => binding.position), [{ x: -30, y: 0, z: 15 }, { x: 30, y: 0, z: 15 }]);
    assert.equal(drill.diameter, 6);
});

test('world X scales the local width after a 90 degree Z rotation', () => {
    const pose = scaleAssemblyPose(member(1, { x: 20, y: 10, z: 5 }, { x: 0, y: 0, z: 90 }), pivot, 'x', 2);
    assert.deepEqual(pose.size, [200, 160, 20]);
    assert.deepEqual(pose.position, { x: 40, y: 10, z: 5 });
    assert.throws(() => scaleAssemblyPose(member(1, pivot, { x: 0, y: 0, z: 45 }), pivot, 'x', 2), /перекосит/);
});

test('axial scaling a drilled board moves hole centers and preserves the drill diameter', () => {
    const drill = { id: 'hole', type: 'drill', face: 'top', diameter: 6, center_u: 70, center_v: 30, depth: 20, through: true };
    const selected = member(1, pivot, pivot, [drill]);
    const scaledX = planAssemblyScale([selected], pivot, 'x', 2).variants[0].data;
    assert.equal(scaledX.length, 400);
    assert.equal(scaledX.operations[0].center_u, 140);
    assert.equal(scaledX.operations[0].center_v, 30);
    assert.equal(scaledX.operations[0].diameter, 6);
    const scaledZ = planAssemblyScale([selected], pivot, 'z', 2).variants[0].data;
    assert.equal(scaledZ.operations[0].depth, 40);
    assert.equal(scaledZ.operations[0].diameter, 6);
});
