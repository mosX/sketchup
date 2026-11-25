import assert from 'node:assert/strict';
import { test } from 'node:test';
import { computeMeshVolume } from 'three-bvh-csg';
import { planAssemblyScale } from '../../resources/js/editor/assemblyScale.js';
import { createOffcutGeometry, createPartGeometry } from '../../resources/js/editor/geometry/csg.js';
import { buildWorkshopDrawing } from '../../resources/js/editor/geometry/workshopDrawing.js';
import { contourPresets, isSimpleContour } from '../../resources/js/editor/operations/shaping.js';
import { compileProjectScriptPayload } from '../../resources/js/editor/scriptApi.js';

const dimensions = { length: 200, width: 100, thickness: 24 };
const rectangle = [{ u: 50, v: -10 }, { u: 90, v: -10 }, { u: 90, v: 20 }, { u: 50, v: 20 }];

for (const [name, operation, expected, tolerance = 1] of [
    ['stopped rabbet', { type: 'rabbet', edge: 'top_left', face: 'top', start: 20, cut_length: 80, width: 12, depth: 6 }, 5760],
    ['side rabbet', { type: 'rabbet', edge: 'top_left', face: 'left', start: 20, cut_length: 80, width: 9, depth: 4 }, 2880],
    ['rectangular pocket on a side', { type: 'pocket', face: 'right', pocket_shape: 'rectangle', center_u: 55, center_v: 12, pocket_length: 40, width: 8, depth: 5 }, 1600],
    ['pocket with rounded corners', { type: 'pocket', face: 'top', pocket_shape: 'rectangle', center_u: 55, center_v: 40, pocket_length: 40, width: 20, corner_radius: 10, depth: 5 }, (800 - (4 - Math.PI) * 100) * 5, 5],
    ['circular pocket', { type: 'pocket', face: 'bottom', pocket_shape: 'circle', center_u: 100, center_v: 40, diameter: 20, depth: 5 }, Math.PI * 100 * 5, 4],
    ['chamfer on a vertical edge', { type: 'edge_chamfer', edge: 'end_right', width: 8 }, 768],
    ['jigsaw notch crossing a stock edge', { type: 'jigsaw_cut', face: 'top', points: rectangle }, 19200],
]) {
    test(`${name} removes the expected volume from the board`, () => {
        const selected = { ...operation, id: 'shape' };
        const part = { dimensions, operations: [selected] };
        const geometry = createPartGeometry(part);
        const offcut = createOffcutGeometry(part, selected);
        try {
            const remaining = computeMeshVolume(geometry) * 1e6;
            const removed = dimensions.length * dimensions.width * dimensions.thickness - remaining;
            assert.ok(Math.abs(removed - expected) < tolerance, `removed ${removed} mm³, expected ${expected} mm³`);
            assert.ok(Math.abs(computeMeshVolume(offcut) * 1e6 - expected) < tolerance);
            const drawing = buildWorkshopDrawing(part, geometry);
            const face = operation.face ?? operation.edge.split('_')[0];
            assert.ok(drawing.views.find(view => view.face === face).marks[0].line.length >= 2);
        } finally {
            geometry.dispose();
            offcut?.dispose();
        }
    });
}

test('oval and arch presets create simple contours while crossing and doubled edges are rejected', () => {
    assert.ok(isSimpleContour(contourPresets('top', dimensions, 'oval')));
    assert.ok(isSimpleContour(contourPresets('top', dimensions, 'notch')));
    assert.equal(isSimpleContour([rectangle[0], rectangle[2], rectangle[1], rectangle[3]]), false);
    assert.equal(isSimpleContour([rectangle[0], rectangle[1], { u: 70, v: -10 }, rectangle[2], rectangle[3]]), false);
});

test('the standalone script API emits all four shaping operation types', () => {
    const compiled = compileProjectScriptPayload(`
        const board = part("Board", { length: 200, width: 100, thickness: 24 });
        board.rabbet({ edge: "top_left", face: "top", width: 10, depth: 4, start: 0, cut_length: 60 });
        board.pocket({ face: "top", pocket_shape: "circle", center_u: 40, center_v: 50, diameter: 30, depth: 5 });
        board.chamfer({ edge: "top_right", width: 3 });
        board.jigsawCut({ face: "top", points: [{u: 60, v: 40}, {u: 90, v: 40}, {u: 90, v: 70}] });
    `);
    assert.deepEqual(compiled.commands[0].data.operations.map(operation => [operation.type, operation.status]), [
        ['rabbet', 'applied'], ['pocket', 'applied'], ['edge_chamfer', 'applied'], ['jigsaw_cut', 'applied'],
    ]);
});

test('axis scaling moves the contour and rabbet without changing their cross section', () => {
    const origin = { x: 0, y: 0, z: 0 };
    const part = { id: 1, name: 'Board', dimensions, operations: [
        { id: 'notch', type: 'jigsaw_cut', face: 'top', points: rectangle },
        { id: 'rabbet', type: 'rabbet', edge: 'top_left', face: 'top', start: 20, cut_length: 80, width: 12, depth: 6 },
    ] };
    const instance = { id: 1, position: origin, rotation: origin };
    const result = planAssemblyScale([{ part, instance }], origin, 'x', 1.5);
    const [notch, rabbet] = result.variants[0].data.operations;
    assert.deepEqual(notch.points, [{ u: 75, v: -10 }, { u: 135, v: -10 }, { u: 135, v: 20 }, { u: 75, v: 20 }]);
    assert.deepEqual([rabbet.start, rabbet.cut_length, rabbet.width, rabbet.depth], [30, 120, 12, 6]);
});
