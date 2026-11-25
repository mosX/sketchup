import { createPartGeometry } from './geometry/csg.js';
import { createMaterialProbe } from './geometry/fastenerMaterial.js';

export const createFastenerMaterialResolver = () => {
    const entries = new Map();
    const geometryFor = (part) => {
        if (entries.has(part.id)) return entries.get(part.id);
        const operations = (part.operations ?? []).filter(operation => operation.status !== 'draft' && operation.enabled !== false);
        let entry;
        try {
            entry = { state: 'ready', geometry: createPartGeometry({ ...part, operations }), machined: operations.length > 0 };
        } catch {
            entry = { state: 'error' };
        }
        entries.set(part.id, entry);
        return entry;
    };
    const get = (part) => {
        if (!(part.operations ?? []).some(operation => operation.status !== 'draft' && operation.enabled !== false)) return null;
        const entry = geometryFor(part);
        if (entry.probeError) return { state: 'error' };
        if (entry.state === 'ready' && !entry.probe) {
            try {
                entry.probe = createMaterialProbe(entry.geometry);
            } catch {
                entry.probeError = true;
                return { state: 'error' };
            }
        }
        return entry;
    };
    const dispose = () => {
        for (const entry of entries.values()) entry?.geometry?.dispose();
        entries.clear();
    };
    return { get, geometryFor, dispose };
};
