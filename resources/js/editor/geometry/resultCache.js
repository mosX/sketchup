export const geometryKey = (part, includeOffcut = null) => JSON.stringify({ dimensions: part.dimensions, operations: part.operations ?? [], includeOffcut });

export const createResultCache = (maxBytes = 64 * 1024 * 1024) => {
    const entries = new Map();
    let bytes = 0;
    const size = value => {
        if (ArrayBuffer.isView(value)) return value.byteLength;
        if (value && typeof value === 'object') return Object.values(value).reduce((sum, item) => sum + size(item), 0);
        return 0;
    };
    const get = key => {
        const entry = entries.get(key);
        if (!entry) return null;
        entries.delete(key);
        entries.set(key, entry);
        return structuredClone(entry.value);
    };
    const set = (key, value) => {
        if (entries.has(key)) { bytes -= entries.get(key).bytes; entries.delete(key); }
        const entryBytes = size(value);
        if (entryBytes > maxBytes) return;
        while (entries.size && (bytes + entryBytes > maxBytes || entries.size >= 64)) {
            const oldest = entries.keys().next().value;
            bytes -= entries.get(oldest).bytes;
            entries.delete(oldest);
        }
        entries.set(key, { value: structuredClone(value), bytes: entryBytes });
        bytes += entryBytes;
    };
    return { get, set, clear: () => { entries.clear(); bytes = 0; }, get bytes() { return bytes; } };
};
