import { deserializeGeometry } from './transfer.js';
import { createResultCache, geometryKey } from './resultCache.js';

const abortError = () => new DOMException('Geometry calculation was superseded.', 'AbortError');

export const createGeometryWorkerClient = () => {
    const pendingRequests = new Map();
    let nextRequestId = 1;
    let worker;
    let developmentBootstrapUrl;
    let watchdog;
    const cache = createResultCache();
    const statistics = { hits: 0, calculations: 0, lastDurationMs: 0 };
    const unpack = data => ({ partGeometry: deserializeGeometry(data.partGeometry), offcutGeometry: deserializeGeometry(data.offcutGeometry) });

    const stopWorker = error => {
        clearTimeout(watchdog);
        worker?.terminate();
        worker = undefined;
        pendingRequests.forEach(({ reject }) => reject(error));
        pendingRequests.clear();
    };

    const monitorProgress = () => {
        clearTimeout(watchdog);
        if (pendingRequests.size) {
            watchdog = setTimeout(() => stopWorker(new Error('Geometry worker did not respond.')), 30000);
        }
    };

    const createWorker = () => {
        if (import.meta.env?.DEV && new URL(import.meta.url).origin !== location.origin) {
            if (!developmentBootstrapUrl) {
                const entry = '../../workers/csg.worker.js?worker_file&type=module';
                const moduleUrl = new URL(entry, import.meta.url);
                // The worker starts on the page's origin and imports Vite's module over CORS.
                const bootstrap = new Blob([`import ${JSON.stringify(moduleUrl.href)};`], { type: 'text/javascript' });
                developmentBootstrapUrl = URL.createObjectURL(bootstrap);
            }

            return new Worker(developmentBootstrapUrl, { type: 'module' });
        }

        return new Worker(new URL('../../workers/csg.worker.js', import.meta.url), { type: 'module' });
    };

    const startWorker = () => {
        const running = createWorker();
        worker = running;
        running.onmessage = ({ data }) => {
            if (worker !== running) return;
            const request = pendingRequests.get(data.id);

            if (!request) return;

            pendingRequests.delete(data.id);
            monitorProgress();

            if (data.error) {
                request.reject(new Error(data.error));
                return;
            }

            const result = { partGeometry: data.partGeometry, offcutGeometry: data.offcutGeometry };
            try {
                const geometry = unpack(result);
                cache.set(request.key, result);
                statistics.lastDurationMs = performance.now() - request.started;
                request.resolve(geometry);
            } catch (error) {
                request.reject(error);
            }
        };
        running.onerror = (event) => {
            if (worker === running) stopWorker(new Error(event.message || 'Geometry worker failed to load.'));
        };
    };

    const cancelAll = () => {
        if (!pendingRequests.size) return;
        stopWorker(abortError());
    };

    const calculate = (part, includeOffcut = null) => new Promise((resolve, reject) => {
        const key = geometryKey(part, includeOffcut);
        const cached = cache.get(key);
        if (cached) { statistics.hits++; resolve(unpack(cached)); return; }
        if (!worker) startWorker();
        statistics.calculations++;
        const id = nextRequestId++;
        pendingRequests.set(id, { resolve, reject, key, started: performance.now() });
        try {
            worker.postMessage({ id, part: JSON.parse(JSON.stringify(part)), includeOffcut });
            if (pendingRequests.size === 1) monitorProgress();
        } catch (error) {
            pendingRequests.delete(id);
            reject(error);
        }
    });

    const dispose = () => {
        stopWorker(abortError());
        cache.clear();
        if (developmentBootstrapUrl) {
            URL.revokeObjectURL(developmentBootstrapUrl);
            developmentBootstrapUrl = undefined;
        }
    };

    return { calculate, cancelAll, dispose, statistics };
};
