import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createPartGeometry } from '../../resources/js/editor/geometry/csg.js';
import { buildWorkshopDrawing } from '../../resources/js/editor/geometry/workshopDrawing.js';
import { workshopPartOperations } from '../../resources/js/editor/workshopPlan.js';

const board = { id: 1, dimensions: { length: 120, width: 60, thickness: 24 }, operations: [] };
const cut = { id: 'cut', type: 'cross_cut', position: 80, miter_angle: 0, bevel_angle: 0, kerf: 2, cut_depth: 24, keep_side: 'start' };
const drill = { id: 'hole', type: 'drill', face: 'end', center_u: 18, center_v: 10, diameter: 5, depth: 8 };

test('stock drawings use canonical face dimensions and show opposite faces when machined', () => {
    const plain = buildWorkshopDrawing(board);
    assert.deepEqual(plain.finished, [120, 60, 24]);
    assert.deepEqual(plain.views.map(view => [view.face, view.width, view.height]), [
        ['top', 120, 60], ['left', 120, 24], ['start', 60, 24],
    ]);
    const output = buildWorkshopDrawing({ ...board, operations: [drill] });
    const end = output.views.find(view => view.face === 'end');
    assert.equal(output.status, 'stock_only');
    assert.deepEqual(end.marks[0].at.map(Math.round), [18, 10]);
});

test('finished drawing derives size and contours from applied CSG and keeps the saw setup number', () => {
    const raw = { ...board, operations: [cut, { ...drill, status: 'draft' }] };
    const applied = { ...raw, operations: workshopPartOperations(raw) };
    const geometry = createPartGeometry(applied);
    try {
        const drawing = buildWorkshopDrawing(applied, geometry);
        assert.deepEqual(drawing.finished.map(Math.round), [81, 60, 24]);
        const top = drawing.views.find(view => view.face === 'top');
        assert.ok(top.lines.length > 2);
        assert.ok(top.lines.every(line => line.every(([u]) => u <= 81.01)));
        assert.deepEqual(top.marks[0].line.map(point => point.map(Math.round)), [[80, 0], [80, 60]]);
        assert.equal(top.marks[0].number, 1);
        assert.ok(!drawing.views.some(view => view.face === 'end'));
    } finally {
        geometry.dispose();
    }
});

test('bottom drilling adds its own face coordinates and appears in the machined contour', () => {
    const part = { ...board, operations: [{ ...drill, face: 'bottom', center_u: 40, center_v: 15, through: true }] };
    const geometry = createPartGeometry(part);
    try {
        const result = buildWorkshopDrawing(part, geometry);
        const underside = result.views.find(view => view.face === 'bottom');
        assert.deepEqual(underside.marks[0].at.map(Math.round), [40, 15]);
        assert.ok(underside.lines.some(line => line.every(([u, v]) => Math.hypot(u - 40, v - 15) < 3)));
    } finally {
        geometry.dispose();
    }
});
