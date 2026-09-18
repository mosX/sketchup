export const draftKey = (userId, projectId, document) => `woodwork:draft:v1:${userId}:${projectId}:${document}`;

export const readDraft = (storage, key) => {
    try {
        const raw = storage.getItem(key);
        if (!raw || raw.length > 2_000_000) return null;
        const record = JSON.parse(raw);
        return record.version === 1 && record.value && typeof record.value === 'object' ? record : null;
    } catch { return null; }
};

export const writeDraft = (storage, key, value, baseTag) => {
    try {
        const text = JSON.stringify({ version: 1, savedAt: Date.now(), baseTag, value });
        if (text.length > 2_000_000) return false;
        storage.setItem(key, text);
        return true;
    } catch { return false; }
};

export const removeDraft = (storage, key) => {
    try { storage.removeItem(key); return true; } catch { return false; }
};
