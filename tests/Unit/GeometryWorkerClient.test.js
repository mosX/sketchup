import test from 'node:test';
import assert from 'node:assert/strict';
import { computeMeshVolume } from 'three-bvh-csg';
import { partSize } from '../../resources/js/editor/geometry/coordinates.js';
import { createGeometryWorkerClient } from '../../resources/js/editor/geometry/workerClient.js';
import { deserializeGeometry } from '../../resources/js/editor/geometry/transfer.js';

test('worker cache reuses geometry across transforms and cancellation rejects obsolete work', async () => {
    const previous = globalThis.Worker;
    const workers = [];
    globalThis.Worker = class {
        messages = [];
        constructor() { workers.push(this); }
        postMessage(message) { this.messages.push(message); }
        terminate() { this.terminated = true; }
    };
    let client;
    try {
        client = createGeometryWorkerClient();
        const part = { dimensions: { length: 100, width: 40, thickness: 20 }, operations: [] };
        const first = client.calculate(part);
        workers[0].onmessage({ data: { id: workers[0].messages[0].id, partGeometry: { attributes: { position: { array: new Float32Array([0, 0, 0]), itemSize: 3 } }, index: null }, offcutGeometry: null } });
        const result = await first;
        result.partGeometry.attributes.position.array[0] = 99;
        const cached = await client.calculate({ ...part, instances: [{ position: { z: 10 } }] });
        assert.equal(cached.partGeometry.attributes.position.array[0], 0);
        assert.equal(client.statistics.hits, 1);
        assert.equal(workers[0].messages.length, 1);
        client.cancelAll();
        assert.equal(workers.length, 1);
        const obsolete = client.calculate({ ...part, operations: [{ type: 'drill' }] });
        const rejected = assert.rejects(obsolete, { name: 'AbortError' });
        client.cancelAll();
        await rejected;
        assert.equal(workers[0].terminated, true);
        assert.equal(workers.length, 1);

        const next = client.calculate({ ...part, dimensions: { ...part.dimensions, length: 200 } });
        assert.equal(workers.length, 2);
        const failed = assert.rejects(next, /failed to load/);
        workers[1].onerror({});
        await failed;
        assert.equal(workers[1].terminated, true);

        const recovered = client.calculate({ ...part, dimensions: { ...part.dimensions, length: 200 } });
        assert.equal(workers.length, 3);
        workers[2].onmessage({ data: { id: workers[2].messages[0].id, partGeometry: null, offcutGeometry: null } });
        assert.equal((await recovered).partGeometry, null);
        result.partGeometry.dispose(); cached.partGeometry.dispose();
    } finally { client?.dispose(); globalThis.Worker = previous; }
});

test('the worker keeps the beveled rip cut when it drops the offcut preview after applying', async () => {
    const previous = globalThis.self;
    let result;
    globalThis.self = {
        postMessage(payload, transfer) {
            result = structuredClone(payload, { transfer });
        },
    };

    try {
        await import('../../resources/js/workers/csg.worker.js');
        const part = {
            dimensions: { length: 182, width: 78.85, thickness: 15 },
            operations: [{
                id: 'rip',
                type: 'rip_cut',
                status: 'draft',
                start_offset: 6,
                end_offset: 9,
                bevel_angle: 45,
                cut_depth: 15,
                cut_direction: 'top_down',
                keep_side: 'opposite',
                reference_side: 'left',
                kerf: 3.2,
            }],
        };

        self.onmessage({ data: { id: 1, part, includeOffcut: 'rip' } });
        assert.ok(result.offcutGeometry);
        assert.ok(result.partGeometry);
        const preview = result.partGeometry.attributes.position.array;
        const offcut = deserializeGeometry(result.offcutGeometry);
        const removedVolume = computeMeshVolume(offcut);
        offcut.dispose();

        part.operations[0].status = 'applied';
        self.onmessage({ data: { id: 2, part } });
        assert.equal(result.offcutGeometry, null);
        assert.deepEqual(result.partGeometry.attributes.position.array, preview);

        const kept = deserializeGeometry(result.partGeometry);
        const keptVolume = computeMeshVolume(kept);
        kept.dispose();
        const { length, width, thickness } = partSize(part);
        assert.ok(removedVolume > 0.01);
        assert.ok(Math.abs(keptVolume + removedVolume - length * width * thickness) < 1e-6);
    } finally {
        globalThis.self = previous;
    }
});
