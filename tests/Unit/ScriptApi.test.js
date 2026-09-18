import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { createScriptApi, joineryScript, machiningScript, starterScript } from '../../resources/js/editor/scriptApi.js';
import { createScriptTransforms } from '../../resources/js/editor/scriptTransforms.js';
import { runScript } from '../../resources/js/editor/scriptSandbox.js';

const execute = (source) => {
    const api = createScriptApi();
    vm.runInNewContext(source, api, { timeout: 100 });
    return api.result();
};

test('bench example reuses one leg definition for four Z-up instances', () => {
    const { commands, logs } = execute(starterScript);
    assert.equal(commands.filter(command => command.type === 'create_part').length, 2);
    const legs = commands.filter(command => command.type === 'create_instance' && command.part_ref === 'item_4');
    assert.equal(legs.length, 4);
    assert.deepEqual(legs.map(command => command.data.position_z), [210, 210, 210, 210]);
    assert.equal(legs[0].data.rotation_y, 90);
    assert.equal(logs[0], 'Bench: 800 x 300 x 450');
});

test('nested groups compose rotations and positions before flattening', () => {
    const api = createScriptApi();
    const root = api.group('Root').rotate([0, 0, 90]).move([100, 0, 0]);
    const child = api.group('Child', root).move([10, 0, 0]);
    child.add(api.part('Part', { length: 20, width: 10, thickness: 5 }), { position: [5, 0, 0] });
    const data = api.result().commands.at(-1).data;
    assert.equal(data.position_x, 100);
    assert.equal(data.position_y, 15);
    assert.equal(data.rotation_z, 90);
});

test('mirroring rotates and reflects the geometry, not just its center', () => {
    const api = createScriptApi();
    const part = api.part('Part', { length: 100, width: 40, thickness: 20 });
    api.mirror(api.add(part, { position: [40, 20, 10], rotation: [15, 25, 35] }), { axis: 'y' });
    const data = api.result().commands.at(-1).data;
    assert.equal(data.position_y, -20);
    assert.equal(data.mirrored, true);
    const math = createScriptTransforms();
    const expected = math.multiply(math.reflection('y'), math.rotation([15, 25, 35]));
    const actual = math.multiply(math.rotation([data.rotation_x, data.rotation_y, data.rotation_z]), math.reflection('x'));
    actual.flat().forEach((v, i) => assert.ok(Math.abs(v - expected.flat()[i]) < 1e-6));
});

test('joinery example copies internal connections and parameter values', () => {
    const result = execute(joineryScript);
    assert.equal(result.connections.length, 2);
    assert.equal(result.connections[1].generate_machining, true);
    assert.notEqual(result.connections[0].primary_ref, result.connections[1].primary_ref);
    const api = createScriptApi({ width: 900 });
    assert.equal(api.parameter('width', { default: 800, min: 300, max: 1000 }), 900);
    assert.throws(() => createScriptApi({ width: 2000 }).parameter('width', { default: 800, max: 1000 }), /between/);
});

test('tool shortcuts use the selected face and through depth', () => {
    const api = createScriptApi();
    api.part('Part', { length: 400, width: 200, thickness: 24 }).drill({ face: 'end', through: true }).groove({ face: 'left' }).plungeRoute({ face: 'right' });
    const operations = api.result().commands[0].data.operations;
    assert.equal(operations[0].center_u, 100);
    assert.equal(operations[0].center_v, 12);
    assert.equal(operations[0].depth, 400);
    assert.equal(operations[1].center_v, 12);
    assert.equal(operations[2].start_v, 12);
});

test('machining example produces an applied drill on a reusable part', () => {
    const { commands } = execute(machiningScript);
    assert.equal(commands[0].data.operations[0].type, 'drill');
    assert.equal(commands[0].data.operations[0].status, 'applied');
    assert.equal(commands[0].data.operations[0].depth, 24);
    assert.equal(commands[1].part_ref, commands[0].temporary_id);
});

test('nested groups reference only handles created by the same script', () => {
    const api = createScriptApi();
    const roof = api.group('Roof', api.group('House'));
    roof.add(api.part('Rafter', { length: 800, width: 80, thickness: 40 }));
    assert.equal(api.result().commands[1].parent_group_ref, 'item_1');
    assert.equal(api.result().commands[3].group_ref, 'item_2');
    assert.throws(() => api.add({ id: 42 }), /Expected a part/);
    assert.throws(() => api.group('Invalid', {}), /Expected a group/);
});

test('script API limits output and rejects non-finite transforms', () => {
    const api = createScriptApi();
    const part = api.part('Part', { length: 100, width: 50, thickness: 20 });
    assert.throws(() => api.add(part, { position: [0, Infinity, 0] }), /finite numbers/);
    for (let index = 0; index < 98; index++) api.add(part);
    assert.throws(() => api.add(part), /Limit: 99/);
    for (let index = 0; index < 50; index++) part.operation('drill', {});
    assert.throws(() => part.operation('drill', {}), /Limit: 50/);
    assert.throws(() => part.operation('unknown', {}), /Unknown operation/);
});

test('sandbox uses an opaque iframe and rejects messages from other windows', async (context) => {
    let listener;
    const frame = {
        attributes: {},
        setAttribute(name, value) { this.attributes[name] = value; },
        remove() {},
        contentWindow: { postMessage(message) {
            if (message.type !== 'run') return;
            // Execute the exact generated worker wrapper, not a second implementation.
            const workerContext = { self: {}, postMessage(payload) {
                listener({ source: frame.contentWindow, data: { type: 'result', payload } });
            } };
            vm.createContext(workerContext);
            vm.runInContext(message.workerSource, workerContext);
            workerContext.onmessage({ data: { source: message.source, values: message.values } });
        } },
    };
    context.mock.method(globalThis, 'setTimeout', () => 1);
    context.mock.method(globalThis, 'clearTimeout', () => {});
    const oldWindow = globalThis.window;
    const oldDocument = globalThis.document;
    globalThis.window = { addEventListener(type, callback) { listener = callback; }, removeEventListener() {} };
    globalThis.document = { createElement() { return frame; }, body: { append() {} } };
    try {
        const result = runScript(machiningScript);
        listener({ source: {}, data: { type: 'result', payload: { error: 'forged' } } });
        listener({ source: frame.contentWindow, data: { type: 'ready' } });
        assert.equal((await result).commands.length, 2);
        assert.equal(frame.attributes.sandbox, 'allow-scripts');
        assert.match(frame.srcdoc, /connect-src 'none'/);
        assert.match(frame.srcdoc, /default-src 'none'/);
        assert.match(frame.srcdoc, /worker-src blob:/);
    } finally {
        globalThis.window = oldWindow;
        globalThis.document = oldDocument;
    }
});
