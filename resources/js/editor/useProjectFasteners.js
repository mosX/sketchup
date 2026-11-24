import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';
import { fastenerFitsFace, fastenerHead, fastenerRow, fastenerStockBounds, inspectFastenerReach } from './fasteners.js';
import { surfaceDimensions } from './operations/shared.js';

const fields = ['source_instance_id', 'target_instance_id', 'catalog_material_id', 'face', 'center_u', 'center_v', 'sink'];
const placementData = fastener => Object.fromEntries(fields.map(key => [key, fastener[key] ?? null]));

export const useProjectFasteners = (projectId, parts, recordHistory, refreshParts, visibleInstanceIds = () => null, geometry = null) => {
    const path = `/v1/projects/${projectId}/fasteners`;
    const active = ref(false);
    const items = ref([]);
    const catalog = ref([]);
    const busy = ref(false);
    const error = ref('');
    const editingId = ref(null);
    const copyDraft = ref(null);
    const copyPlan = ref(null);
    const copyPending = ref(false);
    const selectedIds = ref([]);
    const drillPreview = ref(null);
    const drillReady = ref(false);
    const drillSettings = reactive({
        clearance_diameter: '', pilot: false, pilot_diameter: '', pilot_depth: '',
        countersink: false, countersink_diameter: '', countersink_depth: '',
    });
    const row = reactive({ count: 1, row_axis: 'u', spacing: 40 });
    const draft = reactive({ source_instance_id: null, target_instance_id: 'auto', catalog_material_id: null, face: null, center_u: 0, center_v: 0, sink: 0 });
    const aliases = new Map();
    const instanceById = computed(() => new Map(parts().flatMap(part => (part.instances ?? []).map(instance => [instance.id, { part, instance }]))));
    const boundsById = computed(() => new Map([...instanceById.value].map(([id, item]) => [id, fastenerStockBounds(item)])));
    const visibleBounds = computed(() => {
        const ids = visibleInstanceIds();
        return ids === null ? [...boundsById.value.values()] : ids.flatMap(id => {
            const bounds = boundsById.value.get(id);
            return bounds ? [bounds] : [];
        });
    });
    const material = computed(() => catalog.value.find(item => item.id === draft.catalog_material_id));
    const editing = computed(() => items.value.find(item => item.id === editingId.value) ?? null);
    const source = computed(() => instanceById.value.get(draft.source_instance_id));
    const selectedItems = computed(() => items.value.filter(item => selectedIds.value.includes(item.id) && instanceById.value.has(item.source_instance_id)));
    const drillItems = computed(() => selectedItems.value.filter(item => item.fastener_type !== 'nail'));
    const drillHasTarget = computed(() => drillItems.value.some(item => item.target_instance_id !== null && instanceById.value.has(item.target_instance_id)));
    const canCopyRow = computed(() => selectedItems.value.length >= 2 && selectedItems.value.every(item => (
        item.source_instance_id === selectedItems.value[0].source_instance_id && item.face === selectedItems.value[0].face
    )));
    const copyPayload = computed(() => {
        if (!copyDraft.value?.face) return null;
        const { fastener_ids, source_instance_id, face, target_instance_id, mirror_u, mirror_v } = copyDraft.value;
        return { fastener_ids, source_instance_id, face, target_instance_id: target_instance_id === 'auto' ? null : target_instance_id, mirror_u, mirror_v };
    });
    const cancelDrillPreview = () => {
        drillPreview.value = null;
        drillReady.value = false;
    };
    watch([selectedIds, drillSettings], cancelDrillPreview, { deep: true });
    const faceSize = computed(() => surfaceDimensions(draft.face, source.value?.part.dimensions));
    const previewPositions = computed(() => {
        if (copyDraft.value) return copyPlan.value?.placements ?? [];
        if (!active.value || drillPreview.value || !draft.face || !source.value) return [];
        const stored = editing.value?.catalog_material_id === draft.catalog_material_id ? editing.value : null;
        const length = Number(stored?.length ?? material.value?.fastener_length);
        const diameter = Number(stored?.diameter ?? material.value?.fastener_diameter);
        const fastener_type = stored?.fastener_type ?? material.value?.fastener_type ?? 'screw';
        if (!(length > 0 && diameter > 0)) return [];
        const count = editingId.value ? 1 : Number(row.count);
        const spacing = count > 1 ? Number(row.spacing) : 0;
        if (!Number.isInteger(count) || count < 1 || count > 50 || (count > 1 && !(spacing >= diameter && spacing <= 100000))) return [];
        const positions = fastenerRow({ ...draft, length, diameter, fastener_type }, count, row.row_axis, spacing);
        if (!positions.every(item => fastenerFitsFace(item, faceSize.value))) return [];
        return positions;
    });
    const checkPreview = (materialFor = null) => {
        const choice = (copyDraft.value ?? draft).target_instance_id;
        if (!active.value || choice === null || !previewPositions.value.length) return previewPositions.value;
        const manual = choice !== 'auto' ? boundsById.value.get(choice) : null;
        const targets = choice === 'auto' ? visibleBounds.value : manual ? [manual] : [];
        return previewPositions.value.map(item => {
            const origin = boundsById.value.get(item.source_instance_id);
            if (!origin) return item;
            const reach = inspectFastenerReach(item, origin, targets, materialFor);
            const target_instance_id = choice === 'auto'
                ? (reach.depth > 0 && reach.status !== 'short' ? reach.targetId : null)
                : (manual ? choice : null);
            return { ...item, reach, target_instance_id };
        });
    };
    const previewPlan = computed(() => geometry ? geometry.evaluate(checkPreview) : { result: checkPreview() });
    const preview = computed(() => previewPlan.value.result);
    const checkingGeometry = computed(() => preview.value.some(item => item.reach?.pending));
    if (geometry) watch(() => previewPlan.value.needed, geometry.prepare);

    const reload = async () => { items.value = (await axios.get(path)).data.data; };
    const open = async () => {
        active.value = true;
        busy.value = true;
        error.value = '';
        try {
            const [{ data }] = await Promise.all([axios.get('/v1/materials'), reload()]);
            catalog.value = data.data;
            draft.catalog_material_id ??= catalog.value.find(item => item.fastener_length)?.id ?? null;
        } catch {
            error.value = 'Не удалось загрузить крепёж и каталог.';
        } finally {
            busy.value = false;
        }
    };
    const close = () => {
        active.value = false;
        cancelDrillPreview();
        cancelCopy();
    };
    const clear = () => {
        editingId.value = null;
        draft.face = null;
        draft.target_instance_id = 'auto';
    };
    const pick = (point) => {
        if (busy.value || drillPreview.value) return;
        if (copyDraft.value) {
            const { source_instance_id, face } = point;
            const copy = copyDraft.value;
            if (copy.source_instance_id === source_instance_id && copy.face === face) return;
            copyDraft.value = {
                ...copy, source_instance_id, face,
                target_instance_id: copy.target_instance_id === source_instance_id ? 'auto' : copy.target_instance_id,
            };
            return;
        }
        if (draft.target_instance_id === point.source_instance_id) draft.target_instance_id = 'auto';
        Object.assign(draft, point);
        error.value = '';
    };
    const edit = (id) => {
        if (busy.value || drillPreview.value || copyDraft.value) return;
        const item = items.value.find(fastener => fastener.id === id);
        if (!item) return;
        editingId.value = id;
        Object.assign(draft, placementData(item));
        error.value = '';
    };
    const fail = (requestError, fallback) => {
        const data = requestError.response?.data;
        error.value = Object.values(data?.errors ?? {})[0]?.[0] ?? data?.message ?? fallback;
    };
    const currentId = (id) => {
        while (aliases.has(id)) id = aliases.get(id);
        return id;
    };
    const create = async (payload) => {
        const added = (await axios.post(path, payload)).data.data;
        items.value = [...items.value, ...added];
        return added;
    };
    const update = async (id, payload) => {
        const { data } = (await axios.put(`${path}/${currentId(id)}`, payload)).data;
        items.value = items.value.map(item => item.id === data.id ? data : item);
    };
    const destroy = async (ids) => {
        const current = ids.map(currentId);
        await axios.delete(path, { data: { ids: current } });
        items.value = items.value.filter(item => !current.includes(item.id));
        selectedIds.value = selectedIds.value.filter(id => !current.includes(id));
    };
    const selectFasteners = (ids) => {
        selectedIds.value = ids.slice(0, 50);
        if (drillSettings.clearance_diameter === '') {
            const diameter = Math.max(0, ...drillItems.value.map(item => item.diameter));
            if (diameter > 0) drillSettings.clearance_diameter = diameter;
        }
    };
    const toggleSelection = (id) => selectFasteners(selectedIds.value.includes(id)
        ? selectedIds.value.filter(item => item !== id)
        : [...selectedIds.value, id]);
    const startCopy = () => {
        if (!canCopyRow.value || busy.value || drillPreview.value) return;
        clear();
        copyDraft.value = {
            fastener_ids: selectedItems.value.map(item => item.id), source_instance_id: null, target_instance_id: 'auto',
            face: null, mirror_u: false, mirror_v: false,
        };
        error.value = '';
    };
    const cancelCopy = () => {
        if (!copyDraft.value) return;
        copyDraft.value = null;
        copyPlan.value = null;
        copyPending.value = false;
        error.value = '';
    };
    const sendCopy = async (payload, flags, signal) => (await axios.post(`${path}/copy`, {
        ...payload, ...flags, fastener_ids: payload.fastener_ids.map(currentId),
    }, { signal })).data.data;
    watch(copyPayload, async (payload, previous, onCleanup) => {
        copyPlan.value = null;
        if (!payload) return;
        const controller = new AbortController();
        onCleanup(() => controller.abort());
        copyPending.value = true;
        error.value = '';
        try {
            const plan = await sendCopy(payload, { dry_run: true }, controller.signal);
            if (!controller.signal.aborted && copyPayload.value === payload) copyPlan.value = plan;
        } catch (requestError) {
            if (!controller.signal.aborted && copyPayload.value === payload) fail(requestError, 'Не удалось показать ряд.');
        } finally {
            if (!controller.signal.aborted && copyPayload.value === payload) copyPending.value = false;
        }
    });
    const applyCopy = async () => {
        const payload = copyPayload.value;
        const plan = copyPlan.value;
        if (!plan || !payload || busy.value || copyPending.value || checkingGeometry.value) return;

        busy.value = true;
        error.value = '';
        const targets = copyDraft.value.target_instance_id === 'auto'
            ? { row_target_instance_ids: preview.value.map(item => item.target_instance_id ?? null) }
            : {};
        const place = async (revision) => {
            const result = await sendCopy(payload, { ...targets, dry_run: false, expected_revision: revision });
            items.value = [...items.value, ...result.placements];
            selectFasteners(result.placements.map(item => item.id));
            Object.assign(drillSettings, result.drilling_settings);
            return result.placements;
        };
        try {
            const added = await place(plan.revision);
            const ids = added.map(item => item.id);
            recordHistory({
                label: `Копирование ряда крепежа ×${ids.length}`,
                undo: () => destroy(ids),
                redo: async () => {
                    const current = await sendCopy(payload, { dry_run: true });
                    (await place(current.revision)).forEach((item, index) => aliases.set(currentId(ids[index]), item.id));
                },
            });
            cancelCopy();
        } catch (requestError) {
            if (requestError.response?.status === 409 && copyDraft.value) {
                copyDraft.value = { ...copyDraft.value };
            } else {
                fail(requestError, 'Не удалось скопировать ряд.');
            }
        } finally {
            busy.value = false;
        }
    };
    const toggleCountersink = (enabled) => {
        drillSettings.countersink = enabled;
        if (!enabled) return;
        const diameter = Math.max(...drillItems.value.map(item => item.diameter));
        const head = fastenerHead(diameter);
        const opening = Math.max(head.rimRadius * 2, Number(drillSettings.clearance_diameter) + 0.1);
        if (drillSettings.countersink_diameter === '') drillSettings.countersink_diameter = Math.ceil(opening * 100) / 100;
        if (drillSettings.countersink_depth === '') {
            const headTaper = (head.rimRadius - head.baseRadius) / head.height;
            drillSettings.countersink_depth = Math.max(0.1, Math.ceil((opening - Number(drillSettings.clearance_diameter)) / 2 / headTaper * 100) / 100);
        }
    };
    const drillRequest = async (payload) => (await axios.post(`${path}/drilling`, {
        ...payload,
        fastener_ids: payload.fastener_ids.map(currentId),
    })).data.data;
    const previewDrilling = async () => {
        if (busy.value || !drillItems.value.length) return;
        busy.value = true;
        error.value = '';
        cancelDrillPreview();
        try {
            const payload = { ...drillSettings, fastener_ids: drillItems.value.map(item => item.id), pilot: drillSettings.pilot && drillHasTarget.value };
            const result = await drillRequest({ ...payload, dry_run: true });
            if (!active.value) return;
            if (!result.created_operation_ids.length) {
                error.value = 'Отверстия с этими параметрами уже есть в заготовках.';
                return;
            }
            drillPreview.value = { ...result, payload, parts: result.parts.filter(part => part.operations.length) };
        } catch (requestError) {
            fail(requestError, 'Не удалось рассчитать отверстия.');
        } finally {
            busy.value = false;
        }
    };
    const finishDrillPreview = (plan, success) => {
        if (plan !== drillPreview.value) return;
        if (success) {
            drillReady.value = true;
        } else {
            cancelDrillPreview();
            error.value = 'Не удалось показать геометрию отверстий. Постройте предпросмотр ещё раз.';
        }
    };
    const applyDrilling = async () => {
        if (busy.value || !drillPreview.value || !drillReady.value) return;
        busy.value = true;
        error.value = '';
        const { payload, revision } = drillPreview.value;
        try {
            const result = await drillRequest({ ...payload, dry_run: false, expected_revision: revision });
            let added = result.created_operation_ids;
            cancelDrillPreview();
            recordHistory({
                label: `Подготовка отверстий ×${added.length}`,
                undo: async () => {
                    const plan = await drillRequest({ ...payload, dry_run: true });
                    await drillRequest({ ...payload, dry_run: false, expected_revision: plan.revision, remove_operation_ids: added });
                    await refreshParts();
                },
                redo: async () => {
                    const plan = await drillRequest({ ...payload, dry_run: true });
                    const applied = await drillRequest({ ...payload, dry_run: false, expected_revision: plan.revision });
                    added = applied.created_operation_ids;
                    await refreshParts();
                },
            });
            await refreshParts();
        } catch (requestError) {
            if (requestError.response?.status === 409) {
                cancelDrillPreview();
                error.value = 'Проект изменился. Постройте предпросмотр ещё раз.';
                await refreshParts();
            } else {
                fail(requestError, 'Не удалось применить отверстия.');
            }
        } finally {
            busy.value = false;
        }
    };
    const save = async () => {
        if (busy.value || copyDraft.value || !preview.value.length || checkingGeometry.value) return;
        busy.value = true;
        error.value = '';

        try {
            const after = placementData(preview.value[0]);
            if (editing.value) {
                const id = editing.value.id;
                const before = placementData(editing.value);
                await update(id, after);
                recordHistory({ label: 'Перемещение крепежа', undo: () => update(id, before), redo: () => update(id, after) });
            } else {
                const payload = { ...after, ...(Number(row.count) > 1 ? { ...row } : {}) };
                if (draft.target_instance_id === 'auto' && preview.value.length > 1) {
                    payload.target_instance_id = null;
                    payload.row_target_instance_ids = preview.value.map(item => item.target_instance_id);
                }
                const added = await create(payload);
                const ids = added.map(item => item.id);
                selectFasteners(ids);
                recordHistory({
                    label: `Установка крепежа ×${ids.length}`,
                    undo: () => destroy(ids),
                    redo: async () => (await create(payload)).forEach((item, index) => aliases.set(currentId(ids[index]), item.id)),
                });
            }
            clear();
        } catch (requestError) {
            fail(requestError, 'Не удалось сохранить крепёж.');
        } finally {
            busy.value = false;
        }
    };
    const remove = async () => {
        if (busy.value || !editing.value) return;
        const id = editing.value.id;
        const payload = placementData(editing.value);
        busy.value = true;
        error.value = '';
        try {
            await destroy([id]);
            clear();
            recordHistory({
                label: 'Удаление крепежа',
                undo: async () => aliases.set(currentId(id), (await create(payload))[0].id),
                redo: () => destroy([id]),
            });
        } catch (requestError) {
            fail(requestError, 'Не удалось удалить шуруп.');
        } finally {
            busy.value = false;
        }
    };
    return {
        active, items, catalog, busy, error, editingId, draft, row, faceSize, preview, checkingGeometry, reload, open, close, pick, edit, clear, save, remove,
        canCopyRow, copyDraft, copyPending, startCopy, cancelCopy, applyCopy,
        selectedIds, selectedItems, toggleSelection,
        drillPreview, drillReady, drillSettings, drillItems, drillHasTarget, toggleCountersink, cancelDrillPreview, previewDrilling, finishDrillPreview, applyDrilling,
    };
};
