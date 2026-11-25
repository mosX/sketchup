import test from 'node:test';
import assert from 'node:assert/strict';
import { BoxGeometry, BufferGeometry, EdgesGeometry, Float32BufferAttribute } from 'three';
import { createPartGeometry } from '../../resources/js/editor/geometry/csg.js';
import { calculateOutlinePositions, createPartOutline } from '../../resources/js/editor/geometry/partOutline.js';
import { deserializeGeometry, serializeGeometry } from '../../resources/js/editor/geometry/transfer.js';

test('stock keeps its twelve physical edges and supports transferred contours', () => {
    const stock = new BoxGeometry(1, 2, 3);
    const positions = calculateOutlinePositions(stock);
    assert.equal(positions.length / 6, 12);
    const payload = structuredClone(serializeGeometry(stock, positions).payload);
    const received = deserializeGeometry(payload);
    const outline = createPartOutline(received);
    assert.deepEqual(outline.attributes.position.array, positions);
    stock.dispose();
    received.dispose();
    outline.dispose();
});

test('a split sharp edge stays visible across a T-junction and a flat seam disappears', () => {
    const plate = new BufferGeometry();
    const geometry = vertices => plate.setAttribute('position', new Float32BufferAttribute(vertices.flat(), 3));
    const first = [-1, 0, 0], middle = [0, 0, 0], last = [1, 0, 0];
    const base = [first, last, [0, 0, 1]];
    const split = point => [last, middle, point, middle, first, point];

    assert.equal(calculateOutlinePositions(geometry([...base, ...split([0, 0, -1])])).length, 0);
    const outline = calculateOutlinePositions(geometry([...base, ...split([0, 1, 0])]));
    assert.equal(outline.length / 6, 2);
    assert.equal(Math.abs(outline[0] - outline[3]) + Math.abs(outline[6] - outline[9]), 2);
    plate.dispose();
});

test('drilling and countersinking show the hole rims without lines across flat faces', () => {
    const bore = { type: 'drill', face: 'top', center_u: 50, center_v: 30, diameter: 4, depth: 20, through: true };
    const stock = createPartGeometry({
        dimensions: { length: 100, width: 60, thickness: 20 },
        operations: [bore, { ...bore, diameter: 8, bottom_diameter: 4, depth: 2, through: false }],
    });
    const oldOutline = new EdgesGeometry(stock);
    const outline = calculateOutlinePositions(stock);
    const lengthByRim = { top: 0, bottom: 0 };
    for (let offset = 0; offset < outline.length; offset += 6) {
        const [ax, ay, az, bx, by, bz] = outline.slice(offset, offset + 6);
        if (Math.max(Math.abs(ax), Math.abs(bx)) > 0.49 || Math.max(Math.abs(az), Math.abs(bz)) > 0.29) continue;
        assert.ok(Math.hypot(ax, az) < 0.041 && Math.hypot(bx, bz) < 0.041, `Unexpected segment: ${[ax, ay, az, bx, by, bz]}`);
        assert.ok(Math.abs(ay - by) < 0.0001, `Unexpected vertical segment: ${[ax, ay, az, bx, by, bz]}`);
        if (ay > 0.099) lengthByRim.top += Math.hypot(ax - bx, az - bz);
        if (ay < -0.099) lengthByRim.bottom += Math.hypot(ax - bx, az - bz);
    }
    assert.ok(lengthByRim.top > 0.23);
    assert.ok(lengthByRim.bottom > 0.11);
    assert.ok(outline.length / 3 < oldOutline.attributes.position.count);
    oldOutline.dispose();
    stock.dispose();
});
