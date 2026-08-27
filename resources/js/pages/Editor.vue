<template>
    <div class="editor-shell">
        <header class="editor-header">
            <router-link :to="{ name: 'projects' }" class="editor-logo" aria-label="К списку проектов">
                <span class="brand-mark brand-mark-small">W</span>
            </router-link>
            <div class="min-w-0 flex-1">
                <input v-model="projectName" class="editor-title" aria-label="Название проекта" @change="saveProject">
                <div class="flex items-center gap-2 text-[11px] text-stone-400">
                    <span class="save-dot" :class="{ 'save-dot-active': saveState === 'saving' }"></span>
                    {{ saveLabel }}
                </div>
            </div>

            <div class="editor-mode-switch" aria-label="Режим редактора">
                <button type="button" :class="{ active: editorMode === 'assembly' }" @click="editorMode = 'assembly'">Сборка</button>
                <button type="button" :class="{ active: editorMode === 'part' }" @click="openPart(activePart ?? projects.parts[0])">Заготовка</button>
            </div>

            <button class="button-primary button-compact" type="button" :disabled="saveState === 'saving'" @click="saveCurrent">
                {{ editorMode === 'part' ? 'Сохранить заготовку' : 'Сохранить проект' }}
            </button>
        </header>

        <div v-if="loading" class="grid flex-1 place-items-center bg-stone-100">
            <div class="text-center"><div class="loader mx-auto"></div><p class="mt-4 text-sm text-stone-500">Открываем мастерскую…</p></div>
        </div>

        <div v-else class="editor-workspace editor-workspace-parts">
            <aside class="parts-library">
                <div class="parts-library-header">
                    <div>
                        <span class="inspector-heading">Библиотека</span>
                        <p>{{ projects.parts.length }} {{ pluralParts(projects.parts.length) }}</p>
                    </div>
                    <button class="library-add-button" type="button" aria-label="Создать заготовку" @click="showCreatePart = true">＋</button>
                </div>

                <div v-if="projects.parts.length" class="parts-list">
                    <article v-for="part in projects.parts" :key="part.id" class="part-library-card" :class="{ active: selectedPartId === part.id }" @click="selectPart(part)">
                        <div class="part-library-thumb" aria-hidden="true"><span></span></div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <strong class="truncate">{{ part.name }}</strong>
                                <span class="part-count">×{{ part.instance_count }}</span>
                            </div>
                            <small>{{ formatDimensions(part) }}</small>
                            <small>{{ part.material || 'Материал не указан' }}</small>
                        </div>
                        <div class="part-card-actions">
                            <button type="button" @click.stop="openPart(part)">Редактировать</button>
                            <div class="instance-adder">
                                <input v-model.number="instanceQuantities[part.id]" type="number" min="1" max="20" aria-label="Количество экземпляров">
                                <button type="button" @click.stop="addInstances(part)">На сцену</button>
                            </div>
                        </div>
                    </article>
                </div>

                <button v-else class="library-empty" type="button" @click="showCreatePart = true">
                    <span>＋</span>
                    <strong>Создать первую заготовку</strong>
                    <small>Панель, брус или ножку по точным размерам</small>
                </button>
            </aside>

            <main class="relative min-h-0 min-w-0">
                <div class="view-tabs">
                    <button class="view-tab view-tab-active" type="button">{{ editorMode === 'assembly' ? 'Сборка изделия' : activePart?.name || 'Заготовка' }}</button>
                    <button class="view-tab" type="button" disabled>Спереди</button>
                    <button class="view-tab" type="button" disabled>Сверху</button>
                </div>

                <ThreeViewport
                    :mode="editorMode"
                    :parts="projects.parts"
                    :active-part="partPreview"
                    :selected-instance-id="selectedInstanceId"
                    :selected-operation-id="selectedOperationId"
                    @select-instance="selectInstance"
                    @select-operation="selectedOperationId = $event"
                    @update-operation="updateOperationFromScene"
                />

                <div class="status-bar">
                    <span>Сетка: 50 мм</span>
                    <span>Единицы: мм</span>
                    <span class="ml-auto">{{ editorMode === 'assembly' ? `${totalInstances} деталей на сцене` : 'Редактор заготовки' }}</span>
                </div>
            </main>

            <aside class="inspector-panel">
                <template v-if="editorMode === 'part' && partDraft">
                    <div class="inspector-section">
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="inspector-heading">Заготовка</h2>
                            <span class="status-pill">Связанная</span>
                        </div>
                        <label class="editor-field mt-4">Название<input v-model="partDraft.name"></label>
                        <label class="editor-field mt-3">Материал<input v-model="partDraft.material" placeholder="Например, дуб"></label>
                    </div>

                    <div class="inspector-section">
                        <h2 class="inspector-heading">Размеры, мм</h2>
                        <div class="mt-4 grid grid-cols-3 gap-2">
                            <label class="dimension-field"><span>Длина</span><input v-model.number="partDraft.dimensions.length" type="number" min="1"></label>
                            <label class="dimension-field"><span>Ширина</span><input v-model.number="partDraft.dimensions.width" type="number" min="1"></label>
                            <label class="dimension-field"><span>Толщина</span><input v-model.number="partDraft.dimensions.thickness" type="number" min="1"></label>
                        </div>
                        <label class="editor-field mt-4">Направление волокон
                            <select v-model="partDraft.grain_axis"><option value="length">Вдоль длины</option><option value="width">Вдоль ширины</option></select>
                        </label>
                    </div>

                    <div class="inspector-section">
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="inspector-heading">Операции</h2>
                            <span class="operation-badge">{{ partDraft.operations.length }}</span>
                        </div>

                        <div class="operation-add-grid">
                            <button type="button" @click="addOperation('cross_cut')"><span>╱</span>Поперечный рез</button>
                            <button type="button" @click="addOperation('rip_cut')"><span>↗</span>Продольный рез</button>
                            <button type="button" @click="addOperation('groove')"><span>⊔</span>Паз пилой</button>
                        </div>

                        <div v-if="partDraft.operations.length" class="operation-stack">
                            <article
                                v-for="(operation, index) in partDraft.operations"
                                :key="operation.id"
                                class="operation-row"
                                :class="{ active: operation.id === selectedOperationId, disabled: operation.enabled === false }"
                                @click="selectedOperationId = operation.id"
                            >
                                <input v-model="operation.enabled" type="checkbox" aria-label="Включить операцию" @click.stop>
                                <div><strong>{{ operationLabel(operation) }}</strong><small>{{ operationSummary(operation) }}</small></div>
                                <div class="operation-row-actions">
                                    <button type="button" title="Выше" :disabled="index === 0" @click.stop="moveOperation(index, -1)">↑</button>
                                    <button type="button" title="Ниже" :disabled="index === partDraft.operations.length - 1" @click.stop="moveOperation(index, 1)">↓</button>
                                    <button type="button" title="Удалить" @click.stop="removeOperation(index)">×</button>
                                </div>
                            </article>
                        </div>
                        <p v-else class="operation-empty">Добавьте рез или паз. Операции выполняются сверху вниз.</p>
                    </div>

                    <div v-if="selectedOperation" class="inspector-section">
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="inspector-heading">{{ operationLabel(selectedOperation) }}</h2>
                            <span class="preview-legend"><i></i> плоскость реза</span>
                        </div>

                        <template v-if="selectedOperation.type === 'cross_cut'">
                            <label class="editor-field mt-4">Положение от начала, мм<input v-model.number="selectedOperation.position" type="number" min="0" :max="partDraft.dimensions.length"></label>
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <label class="cut-field"><span>Угол в плане</span><div><input v-model.number="selectedOperation.miter_angle" type="number" min="-60" max="60"><b>°</b></div></label>
                                <label class="cut-field"><span>Наклон диска</span><div><input v-model.number="selectedOperation.bevel_angle" type="number" min="-45" max="45"><b>°</b></div></label>
                            </div>
                            <label class="editor-field mt-3">Оставить часть<select v-model="selectedOperation.keep_side"><option value="start">До линии реза</option><option value="end">После линии реза</option></select></label>
                            <label class="editor-field mt-3">Ширина пропила, мм<input v-model.number="selectedOperation.kerf" type="number" min="0" max="20" step="0.1"></label>
                        </template>

                        <template v-else-if="selectedOperation.type === 'rip_cut'">
                            <div class="mt-4 grid grid-cols-2 gap-2">
                                <label class="editor-field">Базовая кромка<select v-model="selectedOperation.reference_side"><option value="left">Левая</option><option value="right">Правая</option></select></label>
                                <label class="editor-field">Что оставить<select v-model="selectedOperation.keep_side"><option value="opposite">Внутреннюю часть</option><option value="reference">Часть у базы</option></select></label>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <label class="editor-field">Отступ в начале, мм<input v-model.number="selectedOperation.start_offset" type="number" min="0" :max="partDraft.dimensions.width"></label>
                                <label class="editor-field">Отступ в конце, мм<input v-model.number="selectedOperation.end_offset" type="number" min="0" :max="partDraft.dimensions.width"></label>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <label class="cut-field"><span>Наклон диска</span><div><input v-model.number="selectedOperation.bevel_angle" type="number" min="-45" max="45"><b>°</b></div></label>
                                <label class="editor-field">Пропил, мм<input v-model.number="selectedOperation.kerf" type="number" min="0" max="20" step="0.1"></label>
                            </div>
                            <p class="operation-tip">Разные отступы в начале и конце образуют продольный рез под углом — например, коническую ножку.</p>
                        </template>

                        <template v-else>
                            <div class="mt-4 grid grid-cols-2 gap-2">
                                <label class="editor-field">Пласть<select v-model="selectedOperation.face"><option value="top">Верхняя</option><option value="bottom">Нижняя</option></select></label>
                                <label class="editor-field">Направление<select v-model="selectedOperation.direction"><option value="length">Вдоль длины</option><option value="width">Поперёк ширины</option></select></label>
                            </div>
                            <label class="editor-field mt-3">Ось паза от края, мм<input v-model.number="selectedOperation.offset" type="number" min="0" :max="grooveAcrossSize"></label>
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <label class="editor-field">Начало, мм<input v-model.number="selectedOperation.start" type="number" min="0" :max="grooveTravelSize"></label>
                                <label class="editor-field">Конец, мм<input v-model.number="selectedOperation.end" type="number" min="0" :max="grooveTravelSize"></label>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <label class="editor-field">Ширина, мм<input v-model.number="selectedOperation.width" type="number" min="0.1" step="0.1"></label>
                                <label class="editor-field">Глубина, мм<input v-model.number="selectedOperation.depth" type="number" min="0.1" :max="partDraft.dimensions.thickness" step="0.1"></label>
                            </div>
                            <label class="editor-field mt-3">Диаметр диска, мм<input v-model.number="selectedOperation.blade_diameter" type="number" min="1"></label>
                            <p class="operation-tip">Сейчас паз строится прямоугольным объёмом. Геометрию выхода круглого диска добавим на следующем этапе.</p>
                        </template>
                    </div>

                    <div class="inspector-section grid gap-2">
                        <button class="button-primary w-full" type="button" @click="savePart">Применить ко всем ×{{ activePart?.instance_count ?? 0 }}</button>
                        <button class="danger-button" type="button" @click="removePart">Удалить заготовку</button>
                    </div>
                </template>

                <template v-else-if="selectedInstance">
                    <div class="inspector-section">
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="inspector-heading">Экземпляр</h2>
                            <span class="status-pill">{{ selectedPart?.name }}</span>
                        </div>
                        <p class="mt-3 text-xs text-stone-400">Изменяется только размещение этой детали.</p>
                    </div>

                    <div class="inspector-section">
                        <h2 class="inspector-heading">Позиция, мм</h2>
                        <div class="mt-4 grid grid-cols-3 gap-2">
                            <label v-for="axis in axes" :key="`position-${axis}`" class="dimension-field"><span>{{ axis.toUpperCase() }}</span><input v-model.number="selectedInstance.position[axis]" type="number" @change="saveInstance"></label>
                        </div>
                    </div>

                    <div class="inspector-section">
                        <h2 class="inspector-heading">Поворот, °</h2>
                        <div class="mt-4 grid grid-cols-3 gap-2">
                            <label v-for="axis in axes" :key="`rotation-${axis}`" class="dimension-field"><span>{{ axis.toUpperCase() }}</span><input v-model.number="selectedInstance.rotation[axis]" type="number" @change="saveInstance"></label>
                        </div>
                        <label class="mirror-toggle mt-4"><input v-model="selectedInstance.mirrored" type="checkbox" @change="saveInstance"><span>Зеркальный экземпляр</span></label>
                    </div>

                    <div class="inspector-section">
                        <button class="danger-button w-full" type="button" @click="removeInstance">Удалить со сцены</button>
                    </div>
                </template>

                <template v-else>
                    <div class="inspector-section">
                        <div class="flex items-center justify-between gap-4"><h2 class="inspector-heading">Проект</h2><span class="status-pill">Сборка</span></div>
                        <label class="mt-5 block text-xs font-semibold text-stone-500">Описание</label>
                        <textarea v-model="projectDescription" class="editor-textarea" placeholder="Добавьте заметки к проекту" @change="saveProject"></textarea>
                    </div>
                    <div class="inspector-section">
                        <h2 class="inspector-heading">Состав изделия</h2>
                        <div v-for="part in projects.parts" :key="part.id" class="scene-item" :class="{ 'scene-item-active': selectedPartId === part.id }" @click="selectedPartId = part.id">
                            <span class="scene-cube">◇</span><span class="truncate">{{ part.name }}</span><span class="ml-auto text-stone-400">×{{ part.instance_count }}</span>
                        </div>
                        <p v-if="!projects.parts.length" class="mt-4 text-xs leading-5 text-stone-400">Создайте заготовку в библиотеке слева.</p>
                    </div>
                    <div class="inspector-section"><p class="text-xs leading-5 text-stone-400">Щёлкните по детали на сцене, чтобы изменить её позицию и поворот.</p></div>
                </template>
            </aside>
        </div>

        <div v-if="showCreatePart" class="editor-modal-backdrop" @click.self="showCreatePart = false">
            <form class="editor-modal" @submit.prevent="createPart">
                <div class="flex items-start justify-between gap-4">
                    <div><p class="eyebrow">Новая деталь</p><h2 class="mt-2 text-2xl font-semibold tracking-tight">Создать заготовку</h2></div>
                    <button class="icon-button" type="button" aria-label="Закрыть" @click="showCreatePart = false">×</button>
                </div>
                <div class="mt-7 grid gap-4 sm:grid-cols-2">
                    <label class="field-label sm:col-span-2">Название<input v-model="newPart.name" class="field-input" placeholder="Например, ножка стола" required></label>
                    <label class="field-label sm:col-span-2">Материал<input v-model="newPart.material" class="field-input" placeholder="Дуб, ясень, фанера"></label>
                    <label class="field-label">Длина, мм<input v-model.number="newPart.length" class="field-input" type="number" min="1" required></label>
                    <label class="field-label">Ширина, мм<input v-model.number="newPart.width" class="field-input" type="number" min="1" required></label>
                    <label class="field-label">Толщина, мм<input v-model.number="newPart.thickness" class="field-input" type="number" min="1" required></label>
                    <label class="field-label">Количество на сцене<input v-model.number="newPart.quantity" class="field-input" type="number" min="0" max="20"></label>
                </div>
                <p v-if="createError" class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ createError }}</p>
                <div class="mt-7 flex justify-end gap-3"><button class="button-secondary" type="button" @click="showCreatePart = false">Отмена</button><button class="button-primary" type="submit" :disabled="creatingPart">{{ creatingPart ? 'Создаём…' : 'Создать заготовку' }}</button></div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ThreeViewport from '../components/ThreeViewport.vue';
import { useProjectsStore } from '../stores/projects';

const route = useRoute();
const router = useRouter();
const projects = useProjectsStore();
const projectId = Number(route.params.id);
const axes = ['x', 'y', 'z'];
const loading = ref(true);
const projectName = ref('');
const projectDescription = ref('');
const saveState = ref('saved');
const editorMode = ref('assembly');
const selectedPartId = ref(null);
const selectedInstanceId = ref(null);
const selectedOperationId = ref(null);
const partDraft = ref(null);
const showCreatePart = ref(false);
const creatingPart = ref(false);
const createError = ref('');
const instanceQuantities = reactive({});
const newPart = reactive({ name: '', material: '', length: 720, width: 60, thickness: 60, quantity: 0 });

const activePart = computed(() => projects.parts.find((part) => part.id === selectedPartId.value) ?? null);
const selectedPart = computed(() => projects.parts.find((part) => part.instances?.some((instance) => instance.id === selectedInstanceId.value)) ?? null);
const selectedInstance = computed(() => selectedPart.value?.instances.find((instance) => instance.id === selectedInstanceId.value) ?? null);
const totalInstances = computed(() => projects.parts.reduce((total, part) => total + (part.instances?.length ?? 0), 0));
const saveLabel = computed(() => ({ saving: 'Сохраняем…', saved: 'Все изменения сохранены', error: 'Ошибка сохранения' }[saveState.value]));
const selectedOperation = computed(() => partDraft.value?.operations.find((operation) => operation.id === selectedOperationId.value) ?? null);
const grooveTravelSize = computed(() => selectedOperation.value?.direction === 'width' ? partDraft.value?.dimensions.width : partDraft.value?.dimensions.length);
const grooveAcrossSize = computed(() => selectedOperation.value?.direction === 'width' ? partDraft.value?.dimensions.length : partDraft.value?.dimensions.width);
const partPreview = computed(() => {
    if (!activePart.value || !partDraft.value) {
        return activePart.value;
    }

    return {
        ...activePart.value,
        name: partDraft.value.name,
        material: partDraft.value.material,
        dimensions: { ...partDraft.value.dimensions },
        grain_axis: partDraft.value.grain_axis,
        operations: partDraft.value.operations.map((operation) => ({ ...operation })),
    };
});

onMounted(async () => {
    try {
        const [project, parts] = await Promise.all([
            projects.fetchProject(projectId),
            projects.fetchParts(projectId),
        ]);
        projectName.value = project.name;
        projectDescription.value = project.description ?? '';
        selectedPartId.value = parts[0]?.id ?? null;
        parts.forEach((part) => { instanceQuantities[part.id] = 1; });
    } catch (error) {
        if (error.response?.status === 404) {
            await router.replace({ name: 'projects' });
        }
    } finally {
        loading.value = false;
    }
});

const operationId = () => window.crypto?.randomUUID?.() ?? `operation-${Date.now()}-${Math.random().toString(16).slice(2)}`;

const normalizeOperations = (part) => (part.operations ?? []).map((operation) => {
    if (operation.type !== 'angled_cut') {
        return { enabled: true, ...operation, id: operation.id ?? operationId() };
    }

    const angle = Number(operation.angle ?? 0);

    return {
        id: operationId(),
        type: 'cross_cut',
        enabled: true,
        position: operation.end === 'start' ? 0 : Number(part.dimensions.length),
        miter_angle: operation.axis === 'width' ? angle : 0,
        bevel_angle: operation.axis === 'thickness' ? angle : 0,
        kerf: 0,
        keep_side: operation.end === 'start' ? 'end' : 'start',
    };
});

const draftFromPart = (part) => {
    return {
        name: part.name,
        material: part.material ?? '',
        dimensions: { ...part.dimensions },
        grain_axis: part.grain_axis,
        operations: normalizeOperations(part),
    };
};

const openPart = (part) => {
    if (!part) {
        showCreatePart.value = true;
        return;
    }

    selectedPartId.value = part.id;
    selectedInstanceId.value = null;
    partDraft.value = draftFromPart(part);
    selectedOperationId.value = partDraft.value.operations[0]?.id ?? null;
    editorMode.value = 'part';
};

const createOperation = (type) => {
    const length = Number(partDraft.value.dimensions.length);
    const width = Number(partDraft.value.dimensions.width);
    const thickness = Number(partDraft.value.dimensions.thickness);
    const common = { id: operationId(), type, enabled: true };

    if (type === 'cross_cut') {
        return { ...common, position: Math.round(length * 0.9), miter_angle: 0, bevel_angle: 0, kerf: 3.2, keep_side: 'start' };
    }

    if (type === 'rip_cut') {
        return { ...common, reference_side: 'left', start_offset: Math.round(width * 0.25), end_offset: Math.round(width * 0.25), bevel_angle: 0, kerf: 3.2, keep_side: 'opposite' };
    }

    const grooveWidth = Math.min(3.2, Math.max(width / 2, 0.1));

    return {
        ...common,
        face: 'top',
        direction: 'length',
        offset: width / 2,
        start: Math.round(length * 0.1),
        end: Math.round(length * 0.9),
        width: grooveWidth,
        depth: Math.min(5, Math.max(thickness / 3, 0.1)),
        blade_diameter: 190,
    };
};

const addOperation = (type) => {
    const operation = createOperation(type);
    partDraft.value.operations.push(operation);
    selectedOperationId.value = operation.id;
};

const removeOperation = (index) => {
    const wasSelected = partDraft.value.operations[index]?.id === selectedOperationId.value;
    partDraft.value.operations.splice(index, 1);

    if (wasSelected) {
        selectedOperationId.value = partDraft.value.operations[Math.min(index, partDraft.value.operations.length - 1)]?.id ?? null;
    }
};

const moveOperation = (index, direction) => {
    const target = index + direction;

    if (target < 0 || target >= partDraft.value.operations.length) return;

    const [operation] = partDraft.value.operations.splice(index, 1);
    partDraft.value.operations.splice(target, 0, operation);
};

const updateOperationFromScene = (operationId, changes) => {
    const operation = partDraft.value?.operations.find((item) => item.id === operationId);

    if (operation) {
        Object.assign(operation, changes);
    }
};

const operationLabel = (operation) => ({
    cross_cut: 'Поперечный рез',
    rip_cut: 'Продольный рез',
    groove: 'Паз пилой',
}[operation.type] ?? 'Операция');

const operationSummary = (operation) => {
    if (operation.type === 'cross_cut') return `${operation.position} мм · ${operation.miter_angle}° / ${operation.bevel_angle}°`;
    if (operation.type === 'rip_cut') return `${operation.start_offset} → ${operation.end_offset} мм`;

    return `${operation.width} × ${operation.depth} мм`;
};

const selectPart = (part) => {
    if (editorMode.value === 'part') {
        openPart(part);
        return;
    }

    selectedPartId.value = part.id;
};

const createPart = async () => {
    creatingPart.value = true;
    createError.value = '';

    try {
        const part = await projects.createPart(projectId, {
            name: newPart.name,
            material: newPart.material || null,
            length: newPart.length,
            width: newPart.width,
            thickness: newPart.thickness,
            grain_axis: 'length',
            operations: [],
        });
        instanceQuantities[part.id] = 1;

        if (newPart.quantity > 0) {
            await projects.createInstances(projectId, part.id, { quantity: newPart.quantity });
        }

        showCreatePart.value = false;
        newPart.name = '';
        newPart.material = '';
        openPart(projects.parts.find((item) => item.id === part.id));
    } catch (error) {
        createError.value = error.response?.data?.message ?? 'Не удалось создать заготовку.';
    } finally {
        creatingPart.value = false;
    }
};

const savePart = async () => {
    if (!activePart.value || !partDraft.value) {
        return;
    }

    saveState.value = 'saving';

    try {
        const updated = await projects.updatePart(projectId, activePart.value.id, {
            name: partDraft.value.name,
            material: partDraft.value.material || null,
            ...partDraft.value.dimensions,
            grain_axis: partDraft.value.grain_axis,
            operations: partDraft.value.operations,
        });
        partDraft.value = draftFromPart(updated);
        selectedOperationId.value = partDraft.value.operations.find((operation) => operation.id === selectedOperationId.value)?.id
            ?? partDraft.value.operations[0]?.id
            ?? null;
        saveState.value = 'saved';
    } catch {
        saveState.value = 'error';
    }
};

const removePart = async () => {
    if (!activePart.value || !window.confirm(`Удалить заготовку «${activePart.value.name}» и все её экземпляры?`)) {
        return;
    }

    await projects.deletePart(projectId, activePart.value.id);
    selectedPartId.value = projects.parts[0]?.id ?? null;
    partDraft.value = selectedPartId.value ? draftFromPart(projects.parts[0]) : null;
    selectedOperationId.value = partDraft.value?.operations[0]?.id ?? null;
    editorMode.value = projects.parts.length ? 'part' : 'assembly';
};

const addInstances = async (part) => {
    const instances = await projects.createInstances(projectId, part.id, { quantity: instanceQuantities[part.id] || 1 });
    selectedPartId.value = part.id;
    selectedInstanceId.value = instances[0]?.id ?? null;
    editorMode.value = 'assembly';
};

const selectInstance = (instanceId) => {
    selectedInstanceId.value = instanceId;

    if (instanceId) {
        selectedPartId.value = selectedPart.value?.id ?? selectedPartId.value;
    }
};

const saveInstance = async () => {
    if (!selectedInstance.value) {
        return;
    }

    const instance = selectedInstance.value;
    await projects.updateInstance(projectId, instance.id, {
        position_x: instance.position.x,
        position_y: instance.position.y,
        position_z: instance.position.z,
        rotation_x: instance.rotation.x,
        rotation_y: instance.rotation.y,
        rotation_z: instance.rotation.z,
        mirrored: instance.mirrored,
    });
};

const removeInstance = async () => {
    if (!selectedInstance.value) {
        return;
    }

    await projects.deleteInstance(projectId, selectedInstance.value.id);
    selectedInstanceId.value = null;
};

const saveProject = async () => {
    if (!projectName.value.trim()) {
        return;
    }

    saveState.value = 'saving';

    try {
        await projects.updateProject(projectId, { name: projectName.value, description: projectDescription.value || null });
        saveState.value = 'saved';
    } catch {
        saveState.value = 'error';
    }
};

const saveCurrent = () => editorMode.value === 'part' ? savePart() : saveProject();
const formatDimensions = (part) => `${part.dimensions.length} × ${part.dimensions.width} × ${part.dimensions.thickness} мм`;
const pluralParts = (count) => count === 1 ? 'заготовка' : count > 1 && count < 5 ? 'заготовки' : 'заготовок';
</script>
