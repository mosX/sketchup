import test from 'node:test';
import assert from 'node:assert/strict';
import { assemblyShortcutKey, translatedPosition } from '../../resources/js/editor/assemblyMove.js';

test('assembly shortcuts use physical keys on a Cyrillic keyboard', () => {
    assert.equal(assemblyShortcutKey({ code: 'KeyG', key: 'п' }), 'g');
    assert.equal(assemblyShortcutKey({ code: 'KeyZ', key: 'я' }), 'z');
    assert.equal(assemblyShortcutKey({ code: 'KeyR', key: 'к' }), 'r');
});

test('translating a group keeps relative positions and changes only the requested axis', () => {
    const first = { x: 35, y: 6, z: 25.25 };
    const second = { x: -70, y: 6, z: 15.25 };
    assert.deepEqual(translatedPosition(first, 'z', -12.1), { x: 35, y: 6, z: 13.15 });
    assert.deepEqual(translatedPosition(second, 'z', -12.1), { x: -70, y: 6, z: 3.15 });
    assert.equal(first.z, 25.25);
});
