import test from 'node:test';
import assert from 'node:assert/strict';
import { createGeometryWorkerClient } from '../../resources/js/editor/geometry/workerClient.js';

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
        assert.equal(workers.length, 2);
        result.partGeometry.dispose(); cached.partGeometry.dispose();
    } finally { client?.dispose(); globalThis.Worker = previous; }
});
