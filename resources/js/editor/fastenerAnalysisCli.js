import { readFileSync } from 'node:fs';
import { assemblyInstanceState } from './assemblyTree.js';
import { analyzeFasteners } from './fastenerDiagnostics.js';
import { fastenerStockBounds, inspectFastenerReach } from './fasteners.js';
import { createFastenerMaterialResolver } from './fastenerMaterialResolver.js';
import { analyzeAssemblyIntersections } from './geometry/assemblyIntersections.js';

const { parts, groups, fasteners, preview, assembly } = JSON.parse(readFileSync(0, 'utf8'));
const material = createFastenerMaterialResolver();
try {
    let result;
    if (assembly) {
        result = {
            intersections: analyzeAssemblyIntersections(parts, material.geometryFor),
            fasteners: analyzeFasteners(fasteners, parts, groups, material.get, { detailed: true, deadline: performance.now() + 8000 }),
        };
    } else if (preview) {
        const bounds = new Map(parts.flatMap(part => part.instances.map(instance => [instance.id, fastenerStockBounds({ part, instance })])));
        const visible = assemblyInstanceState(groups, parts).visibleInstanceIds.map(id => bounds.get(id));
        result = fasteners.map(placement => {
            const source = bounds.get(placement.source_instance_id);
            const specified = bounds.get(placement.target_instance_id);
            const reach = inspectFastenerReach(placement, source, specified ? [specified] : visible, material.get);
            const target_instance_id = reach.depth > 0 && reach.status !== 'short' ? reach.targetId : null;
            return { target_instance_id, reach };
        });
    } else {
        result = analyzeFasteners(fasteners, parts, groups, material.get);
    }
    process.stdout.write(JSON.stringify(result));
} finally {
    material.dispose();
}
