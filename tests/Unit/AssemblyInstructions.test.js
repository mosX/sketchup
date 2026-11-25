import { test } from 'node:test';
import assert from 'node:assert/strict';
import { describeAssemblySteps, emptyAssemblyStep } from '../../resources/js/editor/assemblyInstructions.js';
import { projectPreviewFaces } from '../../resources/js/editor/projectPreview.js';
import { checkAssemblySequence, planAssemblySequence } from '../../resources/js/editor/assemblySequence.js';

const position = { x: 0, y: 0, z: 0 };
const part = {
    id: 12, name: 'Доска', dimensions: { length: 90, width: 40, thickness: 10 },
    instances: [1, 2, 3].map(id => ({ id, assembly_group_id: null, position, rotation: position })),
};

test('assembly order names early hardware, omitted linked hardware and skipped parts', () => {
    const instances = [1, 2, 3].map(id => ({ id, name: `Panel ${id}` }));
    const early = { id: 7, source_instance_id: 1, target_instance_id: 2 };
    const omitted = { id: 8, source_instance_id: 2, target_instance_id: 1 };
    const steps = [
        { instance_ids: [1], highlight_instance_ids: [2, 3], fastener_ids: [early.id, 99] },
        { instance_ids: [2], fastener_ids: [] },
    ];
    const report = checkAssemblySequence(steps, instances, [early, omitted]);
    assert.deepEqual(report.issues.map(issue => issue.code), ['fastener_before_parts', 'deleted_fastener', 'missing_fastener', 'missing_instance']);
    const [first] = report.issues;
    assert.equal(first.step_index, 0);
    assert.equal(first.context.earliest_step_index, 1);
    assert.deepEqual(first.context.missing_instance_ids, [2]);
    assert.deepEqual(report.issues.at(-1).context.instance_ids, [3]);
});

test('automatic hardware is assigned to the first eligible step and explicit future hardware is reserved', () => {
    const steps = [
        { instance_ids: [1, 2], fastener_ids: [] },
        { instance_ids: [], fastener_ids: null },
        { instance_ids: [], fastener_ids: [9] },
    ];
    const fasteners = [8, 9].map(id => ({ id, source_instance_id: 1, target_instance_id: 2 }));
    const plan = planAssemblySequence(steps, fasteners);
    assert.deepEqual(plan.hardware, [[], [8], [9]]);
    assert.equal(checkAssemblySequence(steps, [{ id: 1 }, { id: 2 }], fasteners, plan).status, 'clear');
    assert.equal(checkAssemblySequence([], [{ id: 1 }], fasteners).status, 'no_steps');
});

test('linked hardware waits for both parts and is counted only once', () => {
    const steps = [[1], [2, 3], []].map(instance_ids => ({ ...emptyAssemblyStep(), instance_ids }));
    const screw = { id: 5, source_instance_id: 1, target_instance_id: 2, fastener_type: 'screw', diameter: 3, length: 25 };
    const result = describeAssemblySteps(steps, [part], [screw]);

    assert.deepEqual(result.map(step => step.activeFastenerIds), [[], [5], []]);
    assert.deepEqual(result[2].visibleFastenerIds, [5]);
    assert.deepEqual(result[1].parts[0].ids, [2, 3]);
    assert.equal(result[1].parts[0].size, '90 × 40 × 10');
});

test('explicit highlights add temporary context and manual hardware stays on its chosen step', () => {
    const steps = [
        { ...emptyAssemblyStep(), instance_ids: [1], highlight_instance_ids: [3, 999] },
        { ...emptyAssemblyStep(), instance_ids: [2], highlight_instance_ids: [] },
        { ...emptyAssemblyStep(), fastener_ids: [5, 404] },
    ];
    const screw = { id: 5, source_instance_id: 1, fastener_type: 'screw', diameter: 3, length: 25 };
    const result = describeAssemblySteps(steps, [part], [screw]);

    assert.deepEqual(result[0].visibleIds, [1, 3]);
    assert.deepEqual(result[1].visibleIds, [1, 2]);
    assert.deepEqual(result.map(step => step.highlighted), [[3], [], []]);
    assert.deepEqual(result.map(step => step.activeFastenerIds), [[], [], [5]]);
});

test('print projection takes the saved Z-up viewing direction and identifies instances', () => {
    const camera = { position: { x: 0, y: 0, z: 100 }, target: position };
    const faces = projectPreviewFaces({ parts: [part] }, camera);

    assert.deepEqual(faces.map(face => face.instanceId), [1, 2, 3]);
    assert.deepEqual(faces[0].points.map(point => point.map(Math.abs)), [[45, 20], [45, 20], [45, 20], [45, 20]]);
});
