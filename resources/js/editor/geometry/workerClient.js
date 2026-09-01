import { deserializeGeometry } from './transfer.js';

const abortError = () => new DOMException('Geometry calculation was superseded.', 'AbortError');

export const createGeometryWorkerClient = () => {
    const pendingRequests = new Map();
    let nextRequestId = 1;
    let worker;

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

            request.resolve({
                partGeometry: deserializeGeometry(data.partGeometry),
                offcutGeometry: deserializeGeometry(data.offcutGeometry),
            });
        };
        worker.onerror = (error) => {
            pendingRequests.forEach(({ reject }) => reject(error));
            pendingRequests.clear();
        };
    };

    const cancelAll = () => {
        pendingRequests.forEach(({ reject }) => reject(abortError()));
        pendingRequests.clear();
        worker.terminate();
        startWorker();
    };

    const calculate = (part, includeOffcut = null) => new Promise((resolve, reject) => {
        const id = nextRequestId++;
        pendingRequests.set(id, { resolve, reject });
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
    };

    startWorker();

    return { calculate, cancelAll, dispose };
};
