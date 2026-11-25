import assert from 'node:assert/strict';
import { test } from 'node:test';
import { computeMeshVolume } from 'three-bvh-csg';
import { planAssemblyScale } from '../../resources/js/editor/assemblyScale.js';
import { curveRouteProfiles, pocketScrewPlacement } from '../../resources/js/editor/geometry/advancedCutters.js';
import { createPartGeometry, createOffcutGeometry } from '../../resources/js/editor/geometry/csg.js';
import { buildWorkshopDrawing } from '../../resources/js/editor/geometry/workshopDrawing.js';
import { createAdvancedOperation, routePresets } from '../../resources/js/editor/operations/advancedMachining.js';
import { compileProjectScriptPayload } from '../../resources/js/editor/scriptApi.js';

const dimensions = { length: 200, width: 100, thickness: 24 };
const boardVolume = 480000;
const operationFor = (type, data = {}) => ({ ...createAdvancedOperation({ id: type, type, status: 'applied' }, dimensions), ...data });
const close = (actual, expected, tolerance) => assert.ok(Math.abs(actual - expected) <= tolerance, `${actual} vs ${expected}`);

for (const [operation, expectedRemaining, tolerance] of [
    [operationFor('drill_row', { center_u: 30, center_v: 40, hole_count: 3, hole_spacing: 25, diameter: 6, depth: 8 }), boardVolume - 3 * Math.PI * 9 * 8, 6],
    [operationFor('curve_route', { points: [{ u: 35, v: 40 }, { u: 95, v: 40 }], cutter_diameter: 8, depth: 4 }), boardVolume - (60 * 8 + Math.PI * 16) * 4, 7],
    [operationFor('taper', { taper_sides: 'two', start_width: 80, end_width: 40 }), 288000, 3],
    [operationFor('turning', { stations: [{ at: 0, diameter: 18 }, { at: 200, diameter: 18 }], smooth_profile: false }), Math.PI * 81 * 200, 86],
]) {
    test(`${operation.type} leaves the expected material and its preview matches the removed stock`, () => {
        const part = { dimensions, operations: [operation] };
        const geometry = createPartGeometry(part);
        const offcut = createOffcutGeometry(part, operation);
        try {
            const remaining = computeMeshVolume(geometry) * 1e6;
            close(remaining, expectedRemaining, tolerance);
            close(remaining + computeMeshVolume(offcut) * 1e6, boardVolume, 4);
            const drawing = buildWorkshopDrawing(part, geometry);
            const top = drawing.views.find(view => view.face === 'top');
            assert.equal(top.marks[0].number, 1);
            if (operation.type === 'drill_row') {
                assert.deepEqual(top.marks[0].locations.map(point => point.map(Math.round)), [[30, 40], [55, 40], [80, 40]]);
            }
        } finally {
            geometry.dispose();
            offcut?.dispose();
        }
    });
}

test('angled drilling places the stepped pocket and pilot on the same axis', () => {
    const operation = operationFor('pocket_screw', { entry_angle: 30, path_angle: 90, depth: 12, pilot_length: 20 });
    const part = { dimensions, operations: [operation] };
    const placement = pocketScrewPlacement(part, operation);
    close(placement.direction.y, -0.5, 1e-8);
    close(placement.direction.z, Math.sqrt(3) / 2, 1e-8);
    close(placement.reach, 0.32, 1e-8);
    const geometry = createPartGeometry(part);
    const offcut = createOffcutGeometry(part, operation);
    try {
        const removed = computeMeshVolume(offcut) * 1e6;
        assert.ok(removed > 100);
        close(computeMeshVolume(geometry) * 1e6 + removed, boardVolume, 4);
    } finally {
        geometry.dispose();
        offcut?.dispose();
    }
});

test('a closed curved pass retains the center island and cuts the selected profile', () => {
    const part = { dimensions };
    for (const [profile, angle] of [['straight', 0], ['dovetail', 14], ['v_groove', 60]]) {
        const operation = operationFor('curve_route', { closed_path: true, points: routePresets('top', dimensions, true), cutter_profile: profile, cutter_angle: angle });
        const rings = curveRouteProfiles(part, operation);
        assert.equal(rings.upper.length, 2);
        const cut = createOffcutGeometry({ ...part, operations: [operation] }, operation);
        try {
            const removed = computeMeshVolume(cut) * 1e6;
            assert.ok(removed > 100 && removed < 12000, `${profile} removed ${removed}`);
        } finally {
            cut?.dispose();
        }
    }
});

test('a narrow return merges the overlapping router passes without double-counting stock', () => {
    const operation = operationFor('curve_route', {
        smooth_path: false, cutter_diameter: 12, depth: 5,
        points: [{ u: 25, v: 30 }, { u: 90, v: 30 }, { u: 90, v: 35 }, { u: 25, v: 35 }],
    });
    const part = { dimensions, operations: [operation] };
    const geometry = createOffcutGeometry(part, operation);
    try {
        const removed = computeMeshVolume(geometry) * 1e6;
        assert.ok(removed > 5500 && removed < 7200, `removed ${removed}`);
    } finally {
        geometry?.dispose();
    }
});

test('scaling a turned leg along its length moves the last profile station to the new end', () => {
    const origin = { x: 0, y: 0, z: 0 };
    const part = { id: 1, name: 'Leg', dimensions, operations: [operationFor('turning')] };
    const instance = { id: 2, position: origin, rotation: origin };
    const plan = planAssemblyScale([{ part, instance }], origin, 'x', 1.5);
    const operation = plan.variants[0].data.operations[0];
    assert.equal(operation.stations.at(-1).at, 300);
    assert.equal(operation.stations.at(-1).diameter, part.operations[0].stations.at(-1).diameter);
});

test('advanced tools are chainable script operations', () => {
    const result = compileProjectScriptPayload(`part("Leg", { length: 200, width: 100, thickness: 24 })
        .drillRow({ hole_count: 3 }).pocketScrew({ entry_angle: 15 }).curveRoute({ closed_path: false })
        .taper({ taper_sides: "two" }).turn({ stations: [{ at: 0, diameter: 20 }, { at: 200, diameter: 10 }] });`);
    assert.deepEqual(result.commands[0].data.operations.map(operation => operation.type), ['drill_row', 'pocket_screw', 'curve_route', 'taper', 'turning']);
});
