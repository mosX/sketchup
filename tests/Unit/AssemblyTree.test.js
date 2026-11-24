import test from 'node:test';
import assert from 'node:assert/strict';
import { assemblyLevelRows, resolveAssemblyPick } from '../../resources/js/editor/assemblyTree.js';

const groups = [
    { id: 1, parent_id: null, name: 'Body', sort_order: 1 },
    { id: 2, parent_id: 1, name: 'Walls', sort_order: 1 },
    { id: 3, parent_id: null, name: 'Lid', sort_order: 2 },
];
const loose = { id: 10, assembly_group_id: null };
const bodyBoard = { id: 11, assembly_group_id: 1 };
const wallBoard = { id: 12, assembly_group_id: 2 };
const lidBoard = { id: 13, assembly_group_id: 3 };
const parts = [{ name: 'Board', instances: [loose, bodyBoard, wallBoard, lidBoard] }];

test('assembly navigation lists child groups first, then direct instances', () => {
    const ids = root => assemblyLevelRows(groups, parts, root).map(row => row.id);
    assert.deepEqual(ids(null), ['group-1', 'group-3', 'instance-10']);
    assert.deepEqual(ids(1), ['group-2', 'instance-11']);
    assert.deepEqual(ids(2), ['instance-12']);
});

test('scene picks resolve to one child at the current assembly level', () => {
    assert.deepEqual(resolveAssemblyPick(groups, wallBoard), { kind: 'group', group: groups[0] });
    assert.deepEqual(resolveAssemblyPick(groups, wallBoard, 1), { kind: 'group', group: groups[1] });
    assert.deepEqual(resolveAssemblyPick(groups, wallBoard, 2), { kind: 'instance', id: 12 });
    assert.deepEqual(resolveAssemblyPick(groups, loose), { kind: 'instance', id: 10 });
    assert.equal(resolveAssemblyPick(groups, loose, 1), null);
    assert.equal(resolveAssemblyPick(groups, lidBoard, 1), null);
});
