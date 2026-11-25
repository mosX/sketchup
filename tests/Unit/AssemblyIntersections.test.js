import assert from 'node:assert/strict';
import { test } from 'node:test';
import { analyzeAssemblyIntersections } from '../../resources/js/editor/geometry/assemblyIntersections.js';
import { createFastenerMaterialResolver } from '../../resources/js/editor/fastenerMaterialResolver.js';
import { analyzeFasteners } from '../../resources/js/editor/fastenerDiagnostics.js';

const instance = (id, { x = 0, y = 0, z = 0, turn = 0, mirrored = false } = {}) => ({
    id, position: { x, y, z }, rotation: { x: 0, y: 0, z: turn }, assembly_group_id: null, mirrored,
});
const stock = (id, dimensions, instances, operations = []) => ({ id, name: `Part ${id}`, dimensions, instances, operations });
const check = (parts) => {
    const materials = createFastenerMaterialResolver();
    try {
        return analyzeAssemblyIntersections(parts, materials.geometryFor);
    } finally {
        materials.dispose();
    }
};

test('overlapping repeated and mirrored instances report the material volume and position', () => {
    const board = stock(1, { length: 100, width: 60, thickness: 20 }, [
        instance(1), instance(2, { x: 90, z: 5, mirrored: true }), instance(3, { x: -100 }),
    ]);
    const report = check([board]);
    assert.equal(report.status, 'complete');
    assert.equal(report.issue_count, 1);
    const [issue] = report.issues;
    assert.deepEqual(issue.context.instance_ids, [1, 2]);
    assert.ok(Math.abs(issue.volume_mm3 - 9000) < 0.05);
    assert.ok(Math.abs(issue.context.intersection_bounds_mm.min.x - 40) < 0.001);
    assert.ok(Math.abs(issue.context.intersection_bounds_mm.max.z - 10) < 0.001);
});

test('an insert in an applied groove clears the check; shifted or draft grooves do not', () => {
    const groove = { id: 'g', type: 'groove', face: 'top', center_u: 50, center_v: 40, path_angle: 0, groove_length: 100, width: 22, depth: 8, blade_diameter: 190, status: 'applied' };
    const board = stock(1, { length: 100, width: 80, thickness: 20 }, [instance(1)], [groove]);
    const insert = stock(2, { length: 70, width: 12, thickness: 5 }, [instance(2, { z: 7 })]);
    const fit = check([board, insert]);
    assert.equal(fit.status, 'complete');
    assert.equal(fit.checked_pairs, 1);
    assert.equal(fit.issue_count, 0);
    const flush = { ...insert, dimensions: { length: 70, width: 22, thickness: 8 }, instances: [instance(2, { z: 6 })] };
    assert.equal(check([board, flush]).issue_count, 0);
    assert.equal(check([board, { ...insert, instances: [instance(2, { y: 12, z: 7 })] }]).issue_count, 1);
    assert.equal(check([{ ...board, operations: [{ ...groove, status: 'draft' }] }, insert]).issue_count, 1);
});

test('rotated parts with overlapping world envelopes and only face contact are excluded', () => {
    const board = stock(1, { length: 110, width: 10, thickness: 20 }, [
        instance(1, { turn: 45 }), instance(2, { x: -Math.SQRT1_2 * 10, y: Math.SQRT1_2 * 10, turn: 45 }),
    ]);
    const report = check([board]);
    assert.equal(report.status, 'complete');
    assert.equal(report.checked_pairs, 0);
    assert.equal(report.issue_count, 0);
});

test('mirroring moves an asymmetric groove to its corresponding position', () => {
    const groove = { id: 'g', type: 'groove', face: 'top', center_u: 80, center_v: 40, path_angle: 90, groove_length: 80, width: 22, depth: 8, blade_diameter: 190, status: 'applied' };
    const board = stock(1, { length: 100, width: 80, thickness: 20 }, [instance(1, { mirrored: true })], [groove]);
    const insert = stock(2, { length: 12, width: 50, thickness: 5 }, [instance(2, { x: -30, z: 7 })]);
    assert.equal(check([board, insert]).issue_count, 0);
    assert.equal(check([board, { ...insert, instances: [instance(2, { x: 30, z: 7 })] }]).issue_count, 1);
});

test('a failed or interrupted geometry check cannot report complete coverage', () => {
    const board = stock(1, { length: 100, width: 60, thickness: 20 }, [instance(1), instance(2, { x: 30 })]);
    const failure = analyzeAssemblyIntersections([board], () => ({ state: 'error' }));
    assert.equal(failure.status, 'partial');
    assert.equal(failure.failed_pairs, 1);
    assert.equal(analyzeAssemblyIntersections([board], () => null, { timeBudgetMs: 0 }).time_limit_reached, true);
});

test('detailed fastener checks catch an exposed tip without a target and a short screw with one', () => {
    const dims = { length: 100, width: 60, thickness: 20 };
    const details = [stock(1, dims, [instance(1)]), stock(2, dims, [instance(2, { z: -20 })])];
    const screw = { id: 10, source_instance_id: 1, face: 'top', center_u: 50, center_v: 30, sink: 0, diameter: 4 };
    const materials = createFastenerMaterialResolver();
    try {
        const single = analyzeFasteners([{ ...screw, length: 25 }], details.slice(0, 1), [], materials.get, { detailed: true });
        assert.equal(single.issues[0].status, 'through');
        assert.ok(Math.abs(single.issues[0].maximum - 5) < 0.001);
        const joined = analyzeFasteners([
            { ...screw, length: 18, target_instance_id: 2 },
            { ...screw, id: 11, length: 25 },
        ], details, [], materials.get, { detailed: true });
        assert.equal(joined.issueCount, 1);
        assert.equal(joined.issues[0].status, 'short');
    } finally {
        materials.dispose();
    }
});
