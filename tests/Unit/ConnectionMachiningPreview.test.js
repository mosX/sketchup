import test from 'node:test';
import assert from 'node:assert/strict';
import { connectionMachiningPreviewParts } from '../../resources/js/editor/connections.js';

test('a machining preview changes only the targeted instances of a shared definition', () => {
    const original = { id: 4, instances: [{ id: 1 }, { id: 2 }, { id: 3 }], operations: [{ id: 'manual' }, { id: 'old' }] };
    const preview = {
        parts: {
            primary: { part_id: 4, instance_id: 1, operations: [{ id: 'tenon' }], replaces: ['old'] },
            secondary: { part_id: 4, instance_id: 2, operations: [{ id: 'mortise' }], replaces: ['old'] },
        },
    };
    const scene = connectionMachiningPreviewParts([original], preview);

    assert.deepEqual(scene.map(part => part.instances.map(instance => instance.id)), [[3], [1], [2]]);
    assert.deepEqual(scene.map(part => part.operations.map(operation => operation.id)), [
        ['manual', 'old'], ['manual', 'tenon'], ['manual', 'mortise'],
    ]);
    assert.equal(original.instances.length, 3);
    assert.deepEqual(original.operations.map(operation => operation.id), ['manual', 'old']);
});
