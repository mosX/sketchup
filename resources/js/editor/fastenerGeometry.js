import { shallowReactive } from 'vue';
import { createMaterialProbe } from './geometry/fastenerMaterial.js';
import { geometryKey } from './geometry/resultCache.js';
import { createGeometryWorkerClient } from './geometry/workerClient.js';

export const createFastenerGeometry = (cache) => {
    const worker = createGeometryWorkerClient(cache);
    const entries = shallowReactive(new Map());

    const evaluate = (check) => {
        const needed = new Map();
        const consulted = new Map();
        const result = check(part => {
            if (consulted.has(part)) return consulted.get(part);
            const operations = (part.operations ?? []).filter(operation => operation.status !== 'draft');
            if (!operations.some(operation => operation.enabled !== false)) {
                consulted.set(part, null);
                return null;
            }
            const stock = { id: part.id, dimensions: part.dimensions, operations };
            const key = geometryKey(stock);
            const entry = entries.get(part.id);
            let material = entry;
            if (!entry || entry.key !== key) {
                needed.set(part.id, { key, stock });
                material = { state: 'pending' };
            }
            consulted.set(part, material);
            return material;
        });
        return { result, needed };
    };

    const prepare = requests => {
        for (const [id, { key, stock }] of requests) {
            const previous = entries.get(id);
            if (previous?.key === key) continue;
            previous?.geometry?.dispose();
            const pending = { key, state: 'pending' };
            entries.set(id, pending);
            void worker.calculate(stock).then(({ partGeometry: geometry }) => {
                if (entries.get(id) !== pending) {
                    geometry?.dispose();
                    return;
                }
                try {
                    if (!geometry) throw new Error('Missing geometry.');
                    entries.set(id, { key, state: 'ready', geometry, probe: createMaterialProbe(geometry) });
                } catch {
                    geometry?.dispose();
                    entries.set(id, { key, state: 'error' });
                }
            }).catch(() => {
                if (entries.get(id) === pending) entries.set(id, { key, state: 'error' });
            });
        }
    };

    const retain = (ids) => {
        const present = new Set(ids);
        for (const [id, entry] of entries) {
            if (present.has(id)) continue;
            entry.geometry?.dispose();
            entries.delete(id);
        }
    };
    const retryFailed = () => {
        for (const [id, entry] of entries) {
            if (entry.state === 'error') entries.delete(id);
        }
    };
    const dispose = () => {
        retain([]);
        worker.dispose();
    };

    return { evaluate, prepare, retain, retryFailed, dispose };
};
