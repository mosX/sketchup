import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import axios from 'axios';
import { computeMeshVolume } from 'three-bvh-csg';
import { effectScope, nextTick } from 'vue';
import { analyzeFasteners } from '../../resources/js/editor/fastenerDiagnostics.js';
import { createFastenerGeometry } from '../../resources/js/editor/fastenerGeometry.js';
import { countersinkHeadOffset, createFastenerMesh, fastenerRow, fastenerStockBounds, inspectFastenerReach } from '../../resources/js/editor/fasteners.js';
import { createPartGeometry } from '../../resources/js/editor/geometry/csg.js';
import { createMaterialProbe } from '../../resources/js/editor/geometry/fastenerMaterial.js';
import { deserializeGeometry, serializeGeometry } from '../../resources/js/editor/geometry/transfer.js';
import { useProjectFasteners } from '../../resources/js/editor/useProjectFasteners.js';

const part = { dimensions: { length: 500, width: 100, thickness: 20 } };
const instance = (z, x = 0) => ({ position: { x, y: 0, z }, rotation: { x: 0, y: 0, z: 0 } });
const source = { part, instance: instance(30) };
const target = { part, instance: instance(10) };
const fastener = { face: 'top', center_u: 250, center_v: 50, sink: 0 };

test('reach is measured from the seating surface through the second stock envelope', () => {
    const stock = fastenerStockBounds(source);
    const second = fastenerStockBounds(target);
    const outside = fastenerStockBounds({ part, instance: instance(10, 600) });
    assert.equal(inspectFastenerReach({ ...fastener, length: 18 }, stock, [second]).status, 'short');
    assert.equal(inspectFastenerReach({ ...fastener, length: 25 }, stock, [second]).status, 'reaches');
    assert.equal(inspectFastenerReach({ ...fastener, length: 25, sink: 17 }, stock, [second]).status, 'through');
    assert.equal(inspectFastenerReach({ ...fastener, length: 25 }, stock, [outside]).status, 'misses');
});

test('automatic reach uses the first stock behind the source and measures shortages and protrusion', () => {
    const stock = fastenerStockBounds({ ...source, instance: { ...source.instance, id: 1 } });
    const closest = fastenerStockBounds({ ...target, instance: { ...instance(7), id: 2, mirrored: true } });
    const farther = fastenerStockBounds({ ...target, instance: { ...instance(-20), id: 3 } });
    const inFront = fastenerStockBounds({ ...target, instance: { ...instance(50), id: 4 } });
    const insideSource = fastenerStockBounds({ part: { dimensions: { ...part.dimensions, thickness: 8 } }, instance: { ...instance(30), id: 5 } });
    const targets = [farther, inFront, insideSource, stock, closest];
    const drill = length => inspectFastenerReach({ ...fastener, length }, stock, targets);

    assert.equal(drill(20).status, 'short');
    assert.equal(drill(20).targetId, 2);
    assert.ok(Math.abs(drill(20).shortage - 3) < 1e-6);
    assert.equal(drill(30).status, 'reaches');
    assert.ok(Math.abs(drill(30).depth - 7) < 1e-6);
    assert.equal(drill(46).status, 'through');
    assert.ok(Math.abs(drill(46).protrusion - 3) < 1e-6);
});

test('machined stock reports a recessed floor and ignores a nearby stock whose channel is empty', () => {
    const front = { ...part, id: 1 };
    const routed = {
        ...part, id: 2, operations: [{ id: 'pocket', type: 'plunge_route', face: 'top', route_mode: 'point',
            start_u: 250, start_v: 50, cutter_diameter: 20, cutter_profile: 'straight', cutter_angle: 0, depth: 12 }],
    };
    const cutThrough = {
        ...part, id: 3, operations: [{ id: 'large', type: 'drill', face: 'top', center_u: 250, center_v: 50, diameter: 20, depth: 20, through: true }],
    };
    const shape = fastenerStockBounds({ part: front, instance: { ...instance(30), id: 1 } });
    const targetFor = (definition, id, z) => fastenerStockBounds({ part: definition, instance: { ...instance(z), id } });
    const geometry = [routed, cutThrough].map(definition => {
        const original = createPartGeometry(definition);
        const received = deserializeGeometry(serializeGeometry(original).payload);
        original.dispose();
        return { received, state: 'ready', probe: createMaterialProbe(received) };
    });
    const material = definition => geometry[definition.id - 2];
    const placed = { ...fastener, diameter: 4, length: 25 };
    const short = inspectFastenerReach(placed, shape, [targetFor(routed, 2, 10)], material);
    assert.equal(short.status, 'short');
    assert.ok(Math.abs(short.shortage - 7) < 1e-4);
    const through = inspectFastenerReach({ ...placed, length: 43 }, shape, [targetFor(routed, 2, 10)], material);
    assert.equal(through.status, 'through');
    assert.ok(Math.abs(through.depth - 8) < 1e-4);
    assert.ok(Math.abs(through.protrusion - 3) < 1e-4);
    const deeper = inspectFastenerReach({ ...placed, length: 45 }, shape, [targetFor(cutThrough, 3, 10), targetFor(front, 4, -10)], material);
    assert.equal(deeper.status, 'reaches');
    assert.equal(deeper.targetId, 4);
    assert.ok(Math.abs(deeper.depth - 5) < 1e-4);
    const unsupported = inspectFastenerReach(placed, targetFor(cutThrough, 3, 30), [targetFor(front, 4, 10)], material);
    assert.equal(unsupported.status, 'unsupported');
    geometry.forEach(entry => entry.received.dispose());
});

test('the server fastener checker reports a machined pocket for both placement and saved analysis', () => {
    const front = { ...part, id: 1, name: 'Skin', instances: [{ ...instance(30), id: 1 }] };
    const back = { ...part, id: 2, name: 'Support', instances: [{ ...instance(10), id: 2 }],
        operations: [{ id: 'pocket', type: 'plunge_route', face: 'top', route_mode: 'point',
            start_u: 250, start_v: 50, cutter_diameter: 20, cutter_profile: 'straight', cutter_angle: 0, depth: 12 }],
    };
    const path = fileURLToPath(new URL('../../resources/js/editor/fastenerAnalysisCli.js', import.meta.url));
    const placement = { ...fastener, id: 1, source_instance_id: 1, target_instance_id: 2, diameter: 4, length: 25 };
    const run = preview => {
        const result = spawnSync(process.execPath, [path], {
            input: JSON.stringify({ parts: [front, back], groups: [], fasteners: [placement], preview }),
            encoding: 'utf8', timeout: 10000,
        });
        assert.equal(result.status, 0, result.stderr);
        return JSON.parse(result.stdout);
    };
    const preview = run(true);
    assert.equal(preview[0].target_instance_id, null);
    assert.equal(preview[0].reach.status, 'short');
    const report = run(false);
    assert.equal(report.approximate, false);
    assert.equal(report.issueCount, 1);
    assert.equal(report.issues[0].status, 'short');
    assert.ok(Math.abs(report.issues[0].minimum - 7) < 1e-4);
});

test('clearance, countersink and a narrow pilot preserve the supported screw path', () => {
    const drill = { type: 'drill', face: 'top', center_u: 250, center_v: 50, depth: 20, status: 'applied' };
    const front = { ...part, id: 1, operations: [
        { ...drill, id: 'clearance', diameter: 4, through: true },
        { ...drill, id: 'countersink', diameter: 8, bottom_diameter: 4, depth: 2, through: false },
    ] };
    const back = { ...part, id: 2, operations: [{ ...drill, id: 'pilot', diameter: 3, depth: 10, through: false }] };
    const source = fastenerStockBounds({ part: front, instance: { ...instance(30), id: 1 } });
    const target = fastenerStockBounds({ part: back, instance: { ...instance(10), id: 2 } });
    const geometry = [front, back].map(definition => createPartGeometry(definition));
    const probes = geometry.map(createMaterialProbe);
    const placement = { ...fastener, sink: 1, diameter: 4, length: 25 };
    const result = inspectFastenerReach(placement, source, [target], definition => ({ state: 'ready', probe: probes[definition.id - 1] }));
    assert.equal(result.status, 'reaches');
    assert.ok(Math.abs(result.depth - 6) < 1e-4);

    const report = analyzeFasteners([{ ...placement, id: 1, source_instance_id: 1, target_instance_id: 2 }],
        [{ ...front, name: 'Обшивка', instances: [source.instance] }, { ...back, name: 'Стойка', instances: [target.instance] }], [],
        definition => ({ state: 'ready', probe: probes[definition.id - 1] }));
    assert.equal(report.issueCount, 0);
    const pending = inspectFastenerReach(placement, source, [target], () => ({ state: 'pending' }));
    assert.equal(pending.pending, true);
    const fallback = inspectFastenerReach(placement, source, [target], () => ({ state: 'error' }));
    assert.equal(fallback.approximate, true);
    geometry.forEach(item => item.dispose());
});

test('placement waits for candidate geometry and refreshes the preview when the worker finishes', async () => {
    const scope = effectScope();
    const originalWorker = globalThis.Worker;
    const originalAdapter = axios.defaults.adapter;
    const requests = [];
    let activeWorker;
    globalThis.Worker = class {
        constructor() { activeWorker = this; }
        postMessage(message) { requests.push(message); }
        terminate() {}
    };
    let apiCalls = 0;
    axios.defaults.adapter = async () => {
        apiCalls++;
        return { data: { data: [] } };
    };
    const geometry = createFastenerGeometry();
    const front = { ...part, id: 1, instances: [{ ...instance(30), id: 1 }] };
    const back = {
        ...part, id: 2, instances: [{ ...instance(10), id: 2 }],
        operations: [{ id: 'pocket', type: 'plunge_route', face: 'top', route_mode: 'point',
            start_u: 250, start_v: 50, cutter_diameter: 20, cutter_profile: 'straight', cutter_angle: 0, depth: 12 }],
    };
    const tool = scope.run(() => useProjectFasteners(1, () => [front, back], () => {}, undefined, undefined, geometry));
    try {
        tool.active.value = true;
        tool.catalog.value = [{ id: 7, fastener_diameter: 4, fastener_length: 25 }];
        tool.draft.catalog_material_id = 7;
        tool.pick({ ...fastener, source_instance_id: 1 });
        await nextTick();
        assert.equal(tool.checkingGeometry.value, true);
        assert.deepEqual(requests.map(request => request.part.id), [2]);
        await tool.save();
        assert.equal(apiCalls, 0);

        const mesh = createPartGeometry(back);
        activeWorker.onmessage({ data: { id: requests[0].id, partGeometry: serializeGeometry(mesh).payload } });
        mesh.dispose();
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(tool.checkingGeometry.value, false);
        assert.equal(tool.preview.value[0].reach.status, 'short');
        assert.equal(tool.preview.value[0].target_instance_id, null);
        assert.equal(requests.length, 1);
    } finally {
        scope.stop();
        geometry.dispose();
        globalThis.Worker = originalWorker;
        axios.defaults.adapter = originalAdapter;
    }
});

test('assembly analysis groups faults by source and reports changed geometry without flagging unlinked fasteners', () => {
    const backing = { ...part, name: 'Стропило', instances: [{ ...instance(10), id: 2 }] };
    const front = { ...part, name: 'Обшивка', instances: [{ ...instance(30), id: 1, assembly_group_id: 8 }] };
    const groups = [{ id: 7, name: 'Беседка', parent_id: null }, { id: 8, name: 'Кровля', parent_id: 7 }];
    const placed = { ...fastener, source_instance_id: 1, target_instance_id: 2, diameter: 3, length: 25 };
    const fasteners = [
        { ...placed, id: 11 },
        { ...placed, id: 12, fastener_type: 'nail', center_u: 270, length: 43 },
        { ...placed, id: 13, fastener_type: 'nail', center_u: 290, length: 45 },
        { ...placed, id: 14, center_u: 1 },
        { ...placed, id: 15, target_instance_id: null },
        { ...placed, id: 16, length: 18 },
    ];

    const report = analyzeFasteners(fasteners, [front, backing], groups);
    assert.equal(report.total, 6);
    assert.equal(report.unchecked, 1);
    assert.equal(report.issueCount, 4);
    const through = report.issues.find(issue => issue.status === 'through');
    assert.equal(through.type, 'nail');
    assert.equal(through.group, 'Беседка / Кровля');
    assert.equal(through.targetName, 'Стропило');
    assert.deepEqual(through.context, { instance_ids: [1, 2], fastener_ids: [12, 13], fastener_status: 'through' });
    assert.ok(Math.abs(through.minimum - 3) < 1e-6);
    assert.ok(Math.abs(through.maximum - 5) < 1e-6);
    assert.deepEqual(report.issues.find(issue => issue.status === 'outside').context.fastener_ids, [14]);
    assert.deepEqual(report.issues.find(issue => issue.status === 'short').context.fastener_ids, [16]);

    const moved = { ...backing, instances: [{ ...instance(10, 600), id: 2 }] };
    const recalculated = analyzeFasteners(fasteners, [front, moved], groups);
    assert.deepEqual(recalculated.issues.find(issue => issue.status === 'misses' && issue.type === 'screw').context.fastener_ids, [11, 16]);
    assert.equal(recalculated.unchecked, 1);
});

test('row placement saves distinct detected targets and allows a manual override', async () => {
    const scope = effectScope();
    const adapter = axios.defaults.adapter;
    const support = { dimensions: { length: 30, width: 100, thickness: 20 } };
    const parts = [
        { ...part, instances: [{ ...instance(30), id: 1 }] },
        { ...support, instances: [{ ...instance(10, -150), id: 2 }, { ...instance(10, 50), id: 3 }, { ...instance(10, -50), id: 4 }] },
    ];
    const tool = scope.run(() => useProjectFasteners(1, () => parts, () => {}, undefined, () => [1, 2, 3]));
    let saved;
    axios.defaults.adapter = async config => {
        saved = JSON.parse(config.data);
        return { data: { data: saved.row_target_instance_ids.map((target_instance_id, index) => ({
            ...fastener, id: index + 10, source_instance_id: 1, target_instance_id, diameter: 4, length: 30,
        })) } };
    };
    try {
        tool.active.value = true;
        tool.catalog.value = [{ id: 7, fastener_diameter: 4, fastener_length: 30 }];
        tool.draft.catalog_material_id = 7;
        tool.pick({ source_instance_id: 1, face: 'top', center_u: 100, center_v: 50 });
        tool.row.count = 3;
        tool.row.spacing = 100;
        assert.deepEqual(tool.preview.value.map(item => [item.target_instance_id, item.reach.status]), [[2, 'reaches'], [null, 'misses'], [3, 'reaches']]);
        tool.draft.target_instance_id = 2;
        assert.deepEqual(tool.preview.value.map(item => item.reach.status), ['reaches', 'misses', 'misses']);
        tool.draft.target_instance_id = null;
        assert.ok(tool.preview.value.every(item => !item.reach));
        tool.draft.target_instance_id = 'auto';
        await tool.save();
        assert.deepEqual(saved.row_target_instance_ids, [2, null, 3]);
        assert.equal(saved.target_instance_id, null);
    } finally {
        axios.defaults.adapter = adapter;
        scope.stop();
    }
});

test('a row retains the same perpendicular coordinate and starts at the picked point', () => {
    const row = fastenerRow(fastener, 3, 'v', 10);
    assert.deepEqual(row.map(item => [item.center_u, item.center_v]), [[250, 50], [250, 60], [250, 70]]);
});

test('nails use a flat circular head while retaining the same pointing direction and reach', () => {
    const placement = { ...fastener, face: 'top', diameter: 3, length: 25 };
    const screw = createFastenerMesh(part, { ...placement, reach: { status: 'reaches' } }, true);
    const nail = createFastenerMesh(part, { ...placement, fastener_type: 'nail', reach: { status: 'through' } }, true);
    const screwHead = screw.children[0].geometry.parameters;
    const nailHead = nail.children[0].geometry.parameters;
    assert.equal(nailHead.radiusTop, nailHead.radiusBottom);
    assert.ok(nailHead.height < screwHead.height);
    assert.ok(screwHead.radiusBottom > screwHead.radiusTop);
    assert.ok(nail.position.equals(screw.position));
    assert.ok(nail.quaternion.equals(screw.quaternion));
    assert.equal(screw.children[0].material.emissiveIntensity, 0);
    assert.ok(nail.children[0].material.emissiveIntensity > 0);
    assert.equal(inspectFastenerReach({ ...placement, fastener_type: 'nail' }, fastenerStockBounds(source), [fastenerStockBounds(target)]).status, 'reaches');
    for (const mesh of [...nail.children, ...screw.children]) mesh.geometry.dispose();
    nail.children[0].material.dispose();
    screw.children[0].material.dispose();
});

test('diagnostic focus adds a visible marker to the selected installed fastener only', () => {
    const placed = { ...fastener, id: 25, diameter: 3, length: 25 };
    const normal = createFastenerMesh(part, placed);
    const focused = createFastenerMesh(part, placed, false, false, 'misses');
    assert.equal(normal.children.length, 3);
    assert.equal(focused.children.length, 4);
    assert.equal(focused.children[3].userData.fastenerId, 25);
    assert.equal(focused.children[3].material.depthTest, false);
    assert.ok(focused.children[0].material.emissiveIntensity > 0);
    for (const mesh of [...normal.children, ...focused.children]) mesh.geometry.dispose();
    normal.children[0].material.dispose();
    focused.children[0].material.dispose();
    focused.children[3].material.dispose();
});

test('nails can be selected for copying without entering the screw drilling selection', () => {
    const scope = effectScope();
    const tool = scope.run(() => useProjectFasteners(1, () => [{ ...part, instances: [{ id: 1 }] }]));
    try {
        tool.items.value = [
            { ...fastener, id: 1, source_instance_id: 1, diameter: 3, fastener_type: 'nail' },
            { ...fastener, id: 2, source_instance_id: 1, diameter: 3, fastener_type: 'nail' },
            { ...fastener, id: 3, source_instance_id: 1, diameter: 4 },
        ];
        tool.toggleSelection(1);
        tool.toggleSelection(2);
        assert.equal(tool.canCopyRow.value, true);
        assert.deepEqual(tool.drillItems.value, []);
        tool.toggleSelection(3);
        assert.deepEqual(tool.drillItems.value.map(item => item.id), [3]);
    } finally {
        scope.stop();
    }
});

test('countersink fit reports the screw head height relative to the stock surface', () => {
    const screw = { diameter: 4 };
    const settings = { clearance_diameter: 4, countersink_diameter: 7.4, countersink_depth: 3 };
    assert.equal(countersinkHeadOffset(screw, settings), 0);
    assert.ok(countersinkHeadOffset(screw, { ...settings, countersink_diameter: 7 }) > 0);
    assert.ok(countersinkHeadOffset(screw, { ...settings, countersink_diameter: 8 }) < 0);
    assert.equal(countersinkHeadOffset(screw, { ...settings, clearance_diameter: 8, countersink_diameter: 10 }), null);
});

test('countersinking an existing bore removes the annular volume up to the specified depth', () => {
    const bore = { id: 'bore', type: 'drill', face: 'top', center_u: 250, center_v: 50, diameter: 4, through: true, depth: 20 };
    const countersink = { ...bore, id: 'countersink', diameter: 8, bottom_diameter: 4, through: false, depth: 2 };
    const original = createPartGeometry({ ...part, operations: [bore] });
    const tapered = createPartGeometry({ ...part, operations: [bore, countersink] });
    const removed = (computeMeshVolume(original) - computeMeshVolume(tapered)) * 1e6;
    const expected = Math.PI * 2 * ((4 ** 2 + 4 * 2 + 2 ** 2) / 3 - 2 ** 2);
    assert.ok(Math.abs(removed - expected) < 0.2, `removed ${removed} mm³, expected ${expected} mm³`);
    original.dispose();
    tapered.dispose();
});

test('row copying commits the latest mirrored preview, selects the new screws and undoes them together', async () => {
    const scope = effectScope();
    const adapter = axios.defaults.adapter;
    const tick = () => new Promise(resolve => setImmediate(resolve));
    const requests = [];
    const history = [];
    const screw = { face: 'top', source_instance_id: 1, target_instance_id: null, diameter: 4, length: 30, center_v: 30, sink: 0 };
    const originals = [{ ...screw, id: 11, center_u: 20 }, { ...screw, id: 12, center_u: 50 }];
    const mirrored = [{ ...screw, source_instance_id: 2, center_u: 480 }, { ...screw, source_instance_id: 2, center_u: 450 }];
    const tool = scope.run(() => useProjectFasteners(1, () => [
        { ...part, instances: [{ ...instance(30), id: 1 }, { ...instance(30, 600), id: 2 }, { ...instance(10, 600), id: 3 }] },
    ], entry => history.push(entry)));
    const respond = (request, data) => request.resolve({ data: { data } });
    axios.defaults.adapter = config => new Promise(resolve => requests.push({
        method: config.method, payload: JSON.parse(config.data), resolve,
    }));

    try {
        tool.active.value = true;
        tool.items.value = originals;
        tool.toggleSelection(11);
        tool.toggleSelection(12);
        tool.startCopy();
        tool.pick({ source_instance_id: 2, face: 'top' });
        await tick();
        tool.copyDraft.value.mirror_u = true;
        await tick();

        const plan = { revision: 6, placements: mirrored, drilling_settings: { countersink: true, countersink_diameter: 8 } };
        respond(requests[1], plan);
        await tick();
        respond(requests[0], { ...plan, placements: originals });
        await tick();
        assert.deepEqual(tool.preview.value.map(item => item.center_u), [480, 450]);
        assert.equal(tool.copyPending.value, false);

        const applied = tool.applyCopy();
        await tick();
        assert.equal(requests[2].payload.mirror_u, true);
        assert.equal(requests[2].payload.expected_revision, 6);
        assert.deepEqual(requests[2].payload.row_target_instance_ids, [3, 3]);
        respond(requests[2], { ...plan, placements: mirrored.map((item, index) => ({ ...item, id: 21 + index })) });
        await applied;
        assert.deepEqual(tool.selectedIds.value, [21, 22]);
        assert.equal(tool.drillSettings.countersink_diameter, 8);
        assert.equal(tool.copyDraft.value, null);

        const undone = history[0].undo();
        await tick();
        assert.equal(requests[3].method, 'delete');
        assert.deepEqual(requests[3].payload.ids, [21, 22]);
        respond(requests[3], null);
        await undone;
        assert.deepEqual(tool.items.value.map(item => item.id), [11, 12]);
    } finally {
        axios.defaults.adapter = adapter;
        scope.stop();
    }
});
