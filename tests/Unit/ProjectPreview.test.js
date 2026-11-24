import test from 'node:test';
import assert from 'node:assert/strict';
import { projectPreviewFaces } from '../../resources/js/editor/projectPreview.js';

const board = (options = {}) => ({
    id: 3,
    dimensions: { length: 630, width: 140, thickness: 18 },
    instances: [{ id: 1, assembly_group_id: null, position: { x: 0, y: 0, z: 9 }, rotation: { x: 0, y: 0, z: 0 }, ...options }],
});

test('preview uses the saved position and Z-up rotation of the actual part', () => {
    const flat = projectPreviewFaces({ parts: [board()] });
    const turned = projectPreviewFaces({ parts: [board({ rotation: { x: 0, y: 0, z: 90 } })] });
    const translated = projectPreviewFaces({ parts: [board({ position: { x: 0, y: 0, z: 109 } })] });
    assert.equal(flat.length, 3);
    assert.notDeepEqual(flat.map(face => face.points), turned.map(face => face.points));
    assert.ok(translated[0].points[0][1] < flat[0].points[0][1]);
});

test('preview excludes descendants of hidden groups and handles empty projects', () => {
    const snapshot = {
        parts: [board({ assembly_group_id: 2 })],
        assembly_groups: [
            { id: 1, parent_id: null, is_visible: false },
            { id: 2, parent_id: 1, is_visible: true },
        ],
    };
    assert.deepEqual(projectPreviewFaces(snapshot), []);
    assert.deepEqual(projectPreviewFaces({}), []);
});
