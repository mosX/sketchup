import { createCsgContext, createOffcutGeometry, createPartGeometry } from '../editor/geometry/csg.js';
import { serializeGeometry } from '../editor/geometry/transfer.js';

self.onmessage = ({ data }) => {
    const { id, part, includeOffcut } = data;
    const context = createCsgContext();
    let partGeometry;
    let offcutGeometry;

    try {
        partGeometry = createPartGeometry(part, context);
        const selectedOperation = includeOffcut
            ? part.operations?.find((operation) => operation.id === includeOffcut)
            : null;
        offcutGeometry = selectedOperation
            ? createOffcutGeometry(part, selectedOperation, context)
            : null;
        const serializedPart = serializeGeometry(partGeometry);
        const serializedOffcut = serializeGeometry(offcutGeometry);
        const transfers = [...new Set([
            ...(serializedPart?.transfers ?? []),
            ...(serializedOffcut?.transfers ?? []),
        ])];

        self.postMessage({
            id,
            partGeometry: serializedPart?.payload ?? null,
            offcutGeometry: serializedOffcut?.payload ?? null,
        }, transfers);
    } catch (error) {
        self.postMessage({ id, error: error instanceof Error ? error.message : String(error) });
    } finally {
        partGeometry?.dispose();
        offcutGeometry?.dispose();
        context.dispose();
    }
};
