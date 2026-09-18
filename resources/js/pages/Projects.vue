<template>
    <AppLayout>
        <div class="mx-auto max-w-7xl px-5 py-10 lg:px-8 lg:py-14">
            <div class="workshop-welcome flex flex-col justify-between gap-6 sm:flex-row sm:items-end">
                <div>
                    <p class="eyebrow">От идеи до готовой вещи</p>
                    <h1 class="mt-3 text-3xl font-semibold tracking-[-0.03em] sm:text-4xl">Ваша мастерская</h1>
                    <p class="mt-3 max-w-xl text-sm leading-6">Продолжите работу над изделием или начните с чистого листа.</p>
                </div>
                <button class="button-accent" type="button" :aria-expanded="showCreateForm" @click="showCreateForm = !showCreateForm">
                    <span class="text-lg leading-none">＋</span> Новый проект
                </button>
            </div>

            <form v-if="showCreateForm" class="mt-8 grid gap-4 rounded-2xl border border-emerald-900/10 bg-emerald-950 p-5 text-white shadow-xl sm:grid-cols-[1fr_1.4fr_auto] sm:items-end" @submit.prevent="createProject">
                <label class="field-label text-emerald-50">Название<input v-model="newProject.name" class="field-input field-input-dark" placeholder="Например, книжный шкаф" required></label>
                <label class="field-label text-emerald-50">Краткое описание<input v-model="newProject.description" class="field-input field-input-dark" placeholder="Материал, назначение, заметки"></label>
                <button class="button-accent" type="submit" :disabled="creating">{{ creating ? 'Создаём…' : 'Создать' }}</button>
            </form>

            <details v-if="projects.templates.length" class="mt-8 rounded-2xl border border-emerald-900/10 bg-white p-5 shadow-sm">
                <summary class="cursor-pointer text-base font-semibold text-emerald-900">Создать из шаблона <span class="ml-2 text-sm font-normal text-stone-500">{{ projects.templates.length }} сохранённых вариантов</span></summary>
                <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <article v-for="template in projects.templates" :key="template.id" class="rounded-xl border border-stone-200 p-4" :class="{ 'border-emerald-500 bg-emerald-50/40': selectedTemplateId === template.id }">
                        <div class="flex items-start justify-between gap-3"><div class="min-w-0"><strong class="block truncate text-sm text-stone-800">{{ template.name }}</strong><small class="mt-1 block text-stone-400">{{ template.part_count }} типов деталей</small></div><button class="text-stone-300 hover:text-red-600" type="button" title="Удалить шаблон" @click="removeTemplate(template)">×</button></div>
                        <p class="mt-3 text-xs text-stone-500">База: {{ formatTemplateDimensions(template.base_dimensions) }} мм</p>
                        <button class="button-secondary button-compact mt-4 w-full" type="button" @click="selectTemplate(template)">Создать по размерам</button>
                    </article>
                </div>

                <form v-if="selectedTemplate" class="mt-5 rounded-xl bg-emerald-950 p-5 text-white" @submit.prevent="instantiateTemplate">
                    <div class="flex items-start justify-between gap-4"><div><p class="text-[10px] font-bold tracking-widest text-lime-300 uppercase">Новый проект</p><h3 class="mt-1 font-semibold">{{ selectedTemplate.name }}</h3></div><button class="text-xl text-white/50 hover:text-white" type="button" aria-label="Закрыть" @click="selectedTemplateId = null">×</button></div>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <label class="field-label text-emerald-50 lg:col-span-2">Название<input v-model="templateProject.name" class="field-input field-input-dark" required></label>
                        <label v-for="field in templateDimensionFields" :key="field.key" class="field-label text-emerald-50">{{ field.label }}, мм<input v-model.number="templateProject.dimensions[field.key]" class="field-input field-input-dark" type="number" min="1" max="100000" step="1" required></label>
                    </div>
                    <div class="mt-4 flex items-center justify-between gap-4"><p class="text-xs text-white/50">Заготовки, позиции и операции будут пересчитаны.</p><button class="button-accent" type="submit" :disabled="instantiatingTemplate">{{ instantiatingTemplate ? 'Создаём…' : 'Создать проект' }}</button></div>
                </form>
            </details>

            <div v-if="projects.items.length" class="project-list-toolbar">
                <h2>Мои проекты <span>{{ projects.items.length }}</span></h2>
                <label><span class="sr-only">Поиск проектов</span><input v-model="search" class="field-input" type="search" placeholder="Найти проект…"></label>
                <label><span class="sr-only">Порядок проектов</span><select v-model="sortOrder" class="field-input"><option value="recent">Сначала недавние</option><option value="name">По названию</option></select></label>
            </div>
            <p v-if="error" role="alert" class="mt-5 rounded-xl bg-red-50 p-4 text-sm text-red-700">{{ error }}</p>

            <div v-if="projects.loading" class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="index in 3" :key="index" class="h-72 animate-pulse rounded-2xl bg-stone-200"></div>
            </div>

            <div v-else-if="projects.items.length" class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <article v-for="project in filteredProjects" :key="project.id" class="project-card group">
                    <router-link :to="{ name: 'editor', params: { id: project.id } }" class="project-preview">
                        <div class="preview-grid" aria-hidden="true"></div>
                        <div class="preview-object" aria-hidden="true">
                            <span></span><span></span><span></span>
                        </div>
                        <span class="project-preview-label">Изделие <span aria-hidden="true">↗</span></span>
                    </router-link>
                    <div class="p-5">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <h2 class="truncate text-lg font-semibold tracking-tight">{{ project.name }}</h2>
                                <p class="mt-1 truncate text-sm text-stone-500">{{ project.description || 'Без описания' }}</p>
                            </div>
                            <button class="icon-button" type="button" aria-label="Удалить проект" @click="removeProject(project)">×</button>
                        </div>
                        <div class="mt-5 flex items-center justify-between border-t border-stone-100 pt-4 text-xs text-stone-400">
                            <span>Изменён {{ formatDate(project.updated_at) }}</span>
                            <router-link class="font-semibold text-emerald-800" :to="{ name: 'editor', params: { id: project.id } }">Открыть →</router-link>
                        </div>
                    </div>
                </article>
            </div>

            <p v-if="projects.items.length && !filteredProjects.length" class="empty-state mt-6">Нет проектов с таким названием. Измените поисковый запрос.</p>

            <div v-if="!projects.loading && !projects.items.length" class="empty-state mt-10">
                <div class="empty-state-mark">＋</div>
                <h2 class="mt-5 text-xl font-semibold">Пока нет проектов</h2>
                <p class="mt-2 text-sm text-stone-500">Создайте первый проект — мы подготовим для него пустую 3D-сцену.</p>
                <button class="button-primary mt-6" type="button" @click="showCreateForm = true">Создать проект</button>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import AppLayout from '../layouts/AppLayout.vue';
import { useProjectsStore } from '../stores/projects';

const projects = useProjectsStore();
const router = useRouter();
const showCreateForm = ref(false);
const creating = ref(false);
const search = ref('');
const sortOrder = ref('recent');
const error = ref('');
const filteredProjects = computed(() => projects.items
    .filter((project) => `${project.name} ${project.description ?? ''}`.toLocaleLowerCase().includes(search.value.trim().toLocaleLowerCase()))
    .slice().sort((a, b) => sortOrder.value === 'name' ? a.name.localeCompare(b.name, 'ru') : new Date(b.updated_at) - new Date(a.updated_at)));
const selectedTemplateId = ref(null);
const instantiatingTemplate = ref(false);
const newProject = reactive({ name: '', description: '' });
const templateProject = reactive({ name: '', dimensions: { width: 1000, depth: 600, height: 750 } });
const templateDimensionFields = [
    { key: 'width', label: 'Ширина X' },
    { key: 'depth', label: 'Глубина Y' },
    { key: 'height', label: 'Высота Z' },
];
const selectedTemplate = computed(() => projects.templates.find((template) => template.id === selectedTemplateId.value) ?? null);

onMounted(() => Promise.all([projects.fetchProjects(), projects.fetchProjectTemplates()]));

const createProject = async () => {
    creating.value = true;
    error.value = '';

    try {
        const project = await projects.createProject(newProject);
        await router.push({ name: 'editor', params: { id: project.id } });
    } catch (failure) {
        error.value = failure.response?.data?.message ?? 'Не удалось создать проект. Попробуйте ещё раз.';
    } finally {
        creating.value = false;
    }
};

const removeProject = async (project) => {
    if (window.confirm(`Удалить проект «${project.name}»?`)) {
        await projects.deleteProject(project.id);
    }
};

const selectTemplate = (template) => {
    selectedTemplateId.value = template.id;
    templateProject.name = `${template.name} — новый проект`;
    templateProject.dimensions.width = template.base_dimensions.width;
    templateProject.dimensions.depth = template.base_dimensions.depth;
    templateProject.dimensions.height = template.base_dimensions.height;
};

const instantiateTemplate = async () => {
    instantiatingTemplate.value = true;

    try {
        const project = await projects.instantiateProjectTemplate(selectedTemplate.value.id, templateProject);
        await router.push({ name: 'editor', params: { id: project.id } });
    } finally {
        instantiatingTemplate.value = false;
    }
};

const removeTemplate = async (template) => {
    if (!window.confirm(`Удалить шаблон «${template.name}»?`)) return;

    await projects.deleteProjectTemplate(template.id);

    if (selectedTemplateId.value === template.id) selectedTemplateId.value = null;
};

const formatTemplateDimensions = (dimensions) => `${Math.round(dimensions.width)} × ${Math.round(dimensions.depth)} × ${Math.round(dimensions.height)}`;

const formatDate = (date) => new Intl.DateTimeFormat('ru', { day: 'numeric', month: 'short' }).format(new Date(date));
</script>
