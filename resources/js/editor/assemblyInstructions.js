import axios from 'axios';
import { computed, ref } from 'vue';
import { partWorkshopCode } from './workshopPlan.js';
import { planAssemblySequence } from './assemblySequence.js';

export const emptyAssemblyStep = () => ({
    title: '', instruction: null, notes: null, instance_ids: [],
    highlight_instance_ids: null, fastener_ids: null, camera: null,
});

export const describeAssemblySteps = (steps, parts, fasteners, plan = planAssemblySequence(steps, fasteners)) => {
    const partByInstance = new Map(parts.flatMap(part => (part.instances ?? []).map(instance => [instance.id, part])));
    const fastenerById = new Map(fasteners.map(item => [item.id, item]));
    const installedFasteners = new Set();
    const assembled = new Set();

    return steps.map((step, index) => {
        const current = step.instance_ids.filter(id => partByInstance.has(id));
        current.forEach(id => assembled.add(id));
        const highlighted = (step.highlight_instance_ids ?? current).filter(id => partByInstance.has(id));
        const currentFasteners = plan.hardware[index].map(id => fastenerById.get(id)).filter(Boolean);
        const partRows = new Map();
        for (const id of current) {
            const part = partByInstance.get(id);
            const row = partRows.get(part.id) ?? {
                id: part.id, name: part.name, code: partWorkshopCode(part.id), ids: [],
                size: [part.dimensions.length, part.dimensions.width, part.dimensions.thickness].join(' × '),
            };
            row.ids.push(id);
            partRows.set(part.id, row);
        }
        const hardware = new Map();
        for (const item of currentFasteners) {
            installedFasteners.add(item.id);
            const label = assemblyFastenerLabel(item);
            hardware.set(label, (hardware.get(label) ?? 0) + 1);
        }
        const fastenerContext = currentFasteners.flatMap(item => [item.source_instance_id, item.target_instance_id]).filter(id => partByInstance.has(id));
        return {
            ...step,
            parts: [...partRows.values()],
            hardware: [...hardware].map(([label, count]) => ({ label, count })),
            visibleIds: [...new Set([...assembled, ...highlighted, ...fastenerContext])],
            highlighted,
            visibleFastenerIds: [...installedFasteners],
            activeFastenerIds: currentFasteners.map(item => item.id),
        };
    });
};

export const assemblyFastenerLabel = item => `${item.fastener_type === 'nail' ? 'Гвоздь' : 'Шуруп'} Ø${item.diameter} × ${item.length} мм`;

export const useAssemblyInstructions = (projectId) => {
    const path = `/v1/projects/${projectId}/assembly-instructions`;
    const saved = ref([]);
    const suggested = ref([]);
    const draft = ref(null);
    const loading = ref(false);
    const saving = ref(false);
    const error = ref('');
    const revision = ref(null);
    const steps = computed(() => draft.value ?? (saved.value.length ? saved.value : suggested.value));
    const editing = computed(() => draft.value !== null);
    const available = computed(() => revision.value !== null);
    const load = async () => {
        if (loading.value) return false;
        loading.value = true;
        error.value = '';
        try {
            const { data } = await axios.get(path);
            saved.value = data.data;
            suggested.value = data.suggested_steps;
            revision.value = data.revision;
            return true;
        } catch {
            error.value = 'Не удалось загрузить инструкцию. Попробуйте открыть её ещё раз.';
            return false;
        } finally {
            loading.value = false;
        }
    };
    const edit = () => {
        draft.value = JSON.parse(JSON.stringify(steps.value));
        error.value = '';
    };
    const cancel = () => { draft.value = null; error.value = ''; };
    const save = async () => {
        if (!editing.value || saving.value) return false;
        saving.value = true;
        error.value = '';
        try {
            const { data } = await axios.put(path, {
                steps: draft.value, expected_revision: revision.value, dry_run: false,
            });
            saved.value = data.data;
            revision.value = data.revision;
            draft.value = null;
            return true;
        } catch (failure) {
            const response = failure.response;
            const fieldMessage = Object.values(response?.data?.errors ?? {}).flat()[0];
            error.value = response?.status === 409
                ? 'Проект изменился. Отмените редактирование и снова откройте инструкцию, чтобы загрузить свежую версию.'
                : fieldMessage ?? 'Не удалось сохранить инструкцию.';
            return false;
        } finally {
            saving.value = false;
        }
    };
    return { steps, saved, draft, editing, available, loading, saving, error, load, edit, cancel, save };
};
