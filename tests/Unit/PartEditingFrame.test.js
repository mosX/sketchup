import test from 'node:test';
import assert from 'node:assert/strict';
import { Group, Ray, Vector3 } from 'three';
import { grooveFaceFrame, partFaceDragPlane, setPartEditingFrame } from '../../resources/js/editor/geometry/coordinates.js';

const stock = { dimensions: { length: 600, width: 100, thickness: 30 } };
const instance = {
    position: { x: 500, y: 200, z: 50 },
    rotation: { x: 0, y: 0, z: 90 },
    mirrored: true,
};

test('the ends of a rotated mirrored stock keep their local identities in the assembly', () => {
    const group = new Group();
    setPartEditingFrame(group, stock, instance);
    const start = group.localToWorld(new Vector3(-3, 0, 0));
    const end = group.localToWorld(new Vector3(3, 0, 0));

    assert.ok(start.distanceTo(new Vector3(5, 0.5, 5)) < 1e-8);
    assert.ok(end.distanceTo(new Vector3(5, 0.5, -1)) < 1e-8);

    setPartEditingFrame(group, stock, null);
    assert.ok(group.localToWorld(new Vector3(-3, 0, 0)).distanceTo(new Vector3(-3, 0.15, 0)) < 1e-8);
});

test('a drag over the end face converts a world ray into the correct local machining coordinates', () => {
    const group = new Group();
    setPartEditingFrame(group, stock, instance);
    const frame = grooveFaceFrame(stock, 'end');
    const plane = partFaceDragPlane(group, frame);
    const expected = frame.center.clone().addScaledVector(frame.u, 0.2).addScaledVector(frame.v, 0.05);
    const worldPoint = group.localToWorld(expected.clone());
    const ray = new Ray(worldPoint.clone().addScaledVector(plane.normal, -2), plane.normal);
    const hit = ray.intersectPlane(plane, new Vector3());

    assert.ok(hit);
    assert.ok(group.worldToLocal(hit).distanceTo(expected) < 1e-8);
    assert.ok(plane.normal.distanceTo(new Vector3(0, 0, 1)) < 1e-8);
});
