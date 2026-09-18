import { ref, watch } from 'vue';
import { draftKey, readDraft, writeDraft, removeDraft } from './draftStorage.js';

export const usePartDraftRecovery = ({ projectId, userId, projects, selectedPartId, partDraft, draftFromPart, openPart }) => {
    const pending = ref([]);
    const warning = ref('');
    let ready = false;
    const touched = new Set();
    const key = id => draftKey(userId(), projectId, `part:${id}`);
    const load = () => {
        touched.clear();
        pending.value = projects.parts.flatMap(part => {
            const record = readDraft(localStorage, key(part.id));
            return record?.value?.dimensions && Array.isArray(record.value.operations) ? [{ partId: part.id, name: part.name, ...record }] : [];
        });
        ready = true;
    };
    const clear = id => {
        touched.delete(id);
        removeDraft(localStorage, key(id));
        pending.value = pending.value.filter(record => record.partId !== id);
    };
    const clearAll = () => {
        projects.parts.forEach(part => clear(part.id));
        pending.value = [];
    };
    const restore = async () => {
        const record = pending.value[0];
        const part = projects.parts.find(item => item.id === record?.partId);
        if (!part) return;
        if (record.baseTag !== part.updated_at && !window.confirm('Заготовка на сервере изменилась. Восстановить старый локальный черновик для просмотра? Сервер изменится только после сохранения.')) return;
        await openPart(part);
        partDraft.value = JSON.parse(JSON.stringify(record.value));
        pending.value = pending.value.filter(item => item.partId !== part.id);
    };
    watch(selectedPartId, id => touched.delete(id), { flush: 'sync' });
    watch(partDraft, draft => {
        if (!ready || !draft) return;
        const part = projects.parts.find(item => item.id === selectedPartId.value);
        if (!part) return;
        if (JSON.stringify(draft) === JSON.stringify(draftFromPart(part))) {
            if (touched.has(part.id)) clear(part.id);
            return;
        }
        touched.add(part.id);
        if (!writeDraft(localStorage, key(part.id), draft, part.updated_at)) warning.value = 'Браузер не смог сохранить локальный черновик. Сохраните заготовку на сервере.';
    }, { deep: true });
    return { pending, warning, load, clear, clearAll, restore, discard: () => { if (pending.value[0]) clear(pending.value[0].partId); } };
};
