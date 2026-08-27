<template>
    <AppLayout>
        <div class="mx-auto max-w-7xl px-5 py-10 lg:px-8 lg:py-14">
            <div class="flex flex-col justify-between gap-6 sm:flex-row sm:items-end">
                <div>
                    <p class="eyebrow">Рабочее пространство</p>
                    <h1 class="mt-3 text-3xl font-semibold tracking-[-0.03em] sm:text-4xl">Мои проекты</h1>
                    <p class="mt-3 max-w-xl text-sm leading-6 text-stone-500">Здесь хранятся ваши модели, размеры и история работы.</p>
                </div>
                <button class="button-primary" type="button" @click="showCreateForm = !showCreateForm">
                    <span class="text-lg leading-none">＋</span> Новый проект
                </button>
            </div>

            <form v-if="showCreateForm" class="mt-8 grid gap-4 rounded-2xl border border-emerald-900/10 bg-emerald-950 p-5 text-white shadow-xl sm:grid-cols-[1fr_1.4fr_auto] sm:items-end" @submit.prevent="createProject">
                <label class="field-label text-emerald-50">Название<input v-model="newProject.name" class="field-input field-input-dark" placeholder="Например, книжный шкаф" required></label>
                <label class="field-label text-emerald-50">Краткое описание<input v-model="newProject.description" class="field-input field-input-dark" placeholder="Материал, назначение, заметки"></label>
                <button class="button-accent" type="submit" :disabled="creating">{{ creating ? 'Создаём…' : 'Создать' }}</button>
            </form>

            <div v-if="projects.loading" class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="index in 3" :key="index" class="h-72 animate-pulse rounded-2xl bg-stone-200"></div>
            </div>

            <div v-else-if="projects.items.length" class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <article v-for="project in projects.items" :key="project.id" class="project-card group">
                    <router-link :to="{ name: 'editor', params: { id: project.id } }" class="project-preview">
                        <div class="preview-grid" aria-hidden="true"></div>
                        <div class="preview-object" aria-hidden="true">
                            <span></span><span></span><span></span>
                        </div>
                        <span class="absolute top-4 left-4 rounded-full bg-white/90 px-3 py-1 text-[10px] font-bold tracking-[0.16em] text-emerald-900 uppercase">Черновик</span>
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

            <div v-else class="empty-state mt-10">
                <div class="empty-state-mark">＋</div>
                <h2 class="mt-5 text-xl font-semibold">Пока нет проектов</h2>
                <p class="mt-2 text-sm text-stone-500">Создайте первый проект — мы подготовим для него пустую 3D-сцену.</p>
                <button class="button-primary mt-6" type="button" @click="showCreateForm = true">Создать проект</button>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import AppLayout from '../layouts/AppLayout.vue';
import { useProjectsStore } from '../stores/projects';

const projects = useProjectsStore();
const router = useRouter();
const showCreateForm = ref(false);
const creating = ref(false);
const newProject = reactive({ name: '', description: '' });

onMounted(() => projects.fetchProjects());

const createProject = async () => {
    creating.value = true;

    try {
        const project = await projects.createProject(newProject);
        await router.push({ name: 'editor', params: { id: project.id } });
    } finally {
        creating.value = false;
    }
};

const removeProject = async (project) => {
    if (window.confirm(`Удалить проект «${project.name}»?`)) {
        await projects.deleteProject(project.id);
    }
};

const formatDate = (date) => new Intl.DateTimeFormat('ru', { day: 'numeric', month: 'short' }).format(new Date(date));
</script>
