import test from 'node:test';
import assert from 'node:assert/strict';
import { createResultCache, geometryKey } from '../../resources/js/editor/geometry/resultCache.js';
import { draftKey, readDraft, writeDraft } from '../../resources/js/editor/draftStorage.js';
import { disposeSceneObjects } from '../../resources/js/editor/sceneAppearance.js';
import { effectScope, nextTick, ref } from 'vue';
import { usePartDraftRecovery } from '../../resources/js/editor/usePartDraftRecovery.js';

test('geometry cache is bounded, LRU, and never shares writable buffers', () => {
    const cache = createResultCache(16), data = { positions: new Float32Array([1, 2]) };
    cache.set('a', data); cache.set('b', data);
    data.positions[0] = 99;
    assert.equal(cache.get('a').positions[0], 1);
    cache.set('c', data);
    assert.equal(cache.get('b'), null);
    const copy = cache.get('a'); copy.positions[0] = 100;
    assert.equal(cache.get('a').positions[0], 1);
    assert.equal(cache.bytes, 16);
    cache.set('oversized', new Float32Array(20));
    assert.equal(cache.get('oversized'), null);
    cache.clear(); assert.equal(cache.bytes, 0);
});
test('geometry keys ignore appearance and transforms but include machining', () => {
    const part = { dimensions: { length: 100 }, operations: [] };
    assert.equal(geometryKey(part), geometryKey({ ...part, name: 'Changed', instances: [{ position: { z: 10 } }] }));
    assert.notEqual(geometryKey(part), geometryKey({ ...part, operations: [{ type: 'drill' }] }));
});
test('drafts are scoped and tolerate corrupt data or unavailable storage', () => {
    const entries = new Map(), storage = { getItem: key => entries.get(key), setItem: (key, value) => entries.set(key, value) };
    const key = draftKey(1, 2, 'script');
    assert.notEqual(key, draftKey(2, 2, 'script'));
    assert.equal(writeDraft(storage, key, { source: 'test' }, 3), true);
    assert.equal(readDraft(storage, key).baseTag, 3);
    entries.set(key, '{broken'); assert.equal(readDraft(storage, key), null);
    assert.equal(writeDraft({ setItem: () => { throw Error('quota'); } }, key, {}, 1), false);
});
test('shared scene geometries are disposed exactly once', () => {
    let count = 0;
    const geometry = { dispose: () => count++ };
    disposeSceneObjects([{ traverse: callback => callback({ geometry }) }, { traverse: callback => callback({ geometry }) }]);
    assert.equal(count, 1);
});

test('part drafts survive switching parts and returning; undo clears a newly edited draft', async () => {
    const previous = globalThis.localStorage, entries = new Map(), scope = effectScope();
    globalThis.localStorage = { getItem: key => entries.get(key), setItem: (key, value) => entries.set(key, value), removeItem: key => entries.delete(key) };
    try {
        const parts = [1, 2].map(id => ({ id, name: `Part ${id}`, updated_at: 'today' }));
        const draftFromPart = part => ({ name: part.name, dimensions: { length: 100 }, operations: [] });
        const selectedPartId = ref(1), partDraft = ref(draftFromPart(parts[0]));
        const recovery = scope.run(() => usePartDraftRecovery({ projectId: 1, userId: () => 1, projects: { parts }, selectedPartId, partDraft, draftFromPart, openPart: async () => {} }));
        recovery.load();
        partDraft.value.name = 'Edited'; await nextTick();
        assert.equal(readDraft(localStorage, draftKey(1, 1, 'part:1')).value.name, 'Edited');
        selectedPartId.value = 2; partDraft.value = draftFromPart(parts[1]); await nextTick();
        selectedPartId.value = 1; partDraft.value = draftFromPart(parts[0]); await nextTick();
        assert.equal(readDraft(localStorage, draftKey(1, 1, 'part:1')).value.name, 'Edited');
        partDraft.value.name = 'New edit'; await nextTick();
        partDraft.value = draftFromPart(parts[0]); await nextTick();
        assert.equal(readDraft(localStorage, draftKey(1, 1, 'part:1')), null);
    } finally { scope.stop(); globalThis.localStorage = previous; }
});
