import { deserializeGeometry } from './transfer.js';
import { createResultCache, geometryKey } from './resultCache.js';

const abortError = () => new DOMException('Geometry calculation was superseded.', 'AbortError');

export const createGeometryWorkerClient = () => {
    const pendingRequests = new Map();
    let nextRequestId = 1;
    let worker;
    const cache = createResultCache();
    const statistics = { hits: 0, calculations: 0, lastDurationMs: 0 };
    const unpack = data => ({ partGeometry: deserializeGeometry(data.partGeometry), offcutGeometry: deserializeGeometry(data.offcutGeometry) });

    const startWorker = () => {
        worker = new Worker(new URL('../../workers/csg.worker.js', import.meta.url), { type: 'module' });
        worker.onmessage = ({ data }) => {
            const request = pendingRequests.get(data.id);

            if (!request) return;

            pendingRequests.delete(data.id);

            if (data.error) {
                request.reject(new Error(data.error));
                return;
            }

            const result = { partGeometry: data.partGeometry, offcutGeometry: data.offcutGeometry };
            cache.set(request.key, result);
            statistics.lastDurationMs = performance.now() - request.started;
            request.resolve(unpack(result));
        };
        worker.onerror = (error) => {
            pendingRequests.forEach(({ reject }) => reject(error));
            pendingRequests.clear();
        };
    };

    const cancelAll = () => {
        if (!pendingRequests.size) return;
        pendingRequests.forEach(({ reject }) => reject(abortError()));
        pendingRequests.clear();
        worker.terminate();
        startWorker();
    };

    const calculate = (part, includeOffcut = null) => new Promise((resolve, reject) => {
        const key = geometryKey(part, includeOffcut);
        const cached = cache.get(key);
        if (cached) { statistics.hits++; resolve(unpack(cached)); return; }
        statistics.calculations++;
        const id = nextRequestId++;
        pendingRequests.set(id, { resolve, reject, key, started: performance.now() });
        worker.postMessage({
            id,
            part: JSON.parse(JSON.stringify(part)),
            includeOffcut,
        });
    });

    const dispose = () => {
        pendingRequests.forEach(({ reject }) => reject(abortError()));
        pendingRequests.clear();
        worker.terminate();
        cache.clear();
    };

    startWorker();

    return { calculate, cancelAll, dispose, statistics };
};
