<template>
    <dialog ref="dialog" class="fixed inset-0 m-auto max-h-[85dvh] w-[min(94vw,640px)] overflow-auto rounded-2xl border border-stone-200 bg-white p-6 text-stone-800 shadow-2xl backdrop:bg-stone-950/60" aria-labelledby="versions-title" @cancel.prevent="close" @keydown.stop>
        <header class="flex items-center justify-between gap-4"><h2 id="versions-title" class="text-lg font-semibold">Версии проекта</h2><button class="icon-button" :disabled="busy" aria-label="Закрыть" @click="close">×</button></header>
        <p class="mt-3 text-sm leading-6 text-stone-500">Версия сохраняет заготовки, сборку, соединения и сохранённый сценарий. Несохранённые черновики в неё не входят. Перед восстановлением автоматически создаётся резервная версия.</p>
        <form class="my-5 flex gap-2" @submit.prevent="capture"><input v-model="label" class="field-input min-w-0 flex-1" maxlength="100" placeholder="Например: до изменения крыши" aria-label="Название версии"><button class="button-primary button-compact" :disabled="busy">Сохранить версию</button></form>
        <p v-if="error" role="alert" class="my-3 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>
        <p v-if="notice" role="status" class="my-3 text-sm text-emerald-800">{{ notice }}</p>
        <p v-if="!versions.length && !busy" class="py-6 text-sm text-stone-500">Сохранённых версий пока нет.</p>
        <ul class="grid gap-2"><li v-for="version in versions" :key="version.id" class="flex items-center justify-between gap-3 rounded-xl border border-stone-200 p-3"><div class="min-w-0"><strong class="block truncate text-sm">{{ version.label }}</strong><span class="text-xs text-stone-500">{{ new Date(version.created_at).toLocaleString('ru') }} · ревизия {{ version.revision }}</span></div><button class="button-secondary button-compact" :disabled="busy" @click="restore(version)">Восстановить</button></li></ul>
    </dialog>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import axios from 'axios';
const props = defineProps({ projectId: { type: Number, required: true }, onRestored: { type: Function, required: true } });
const emit = defineEmits(['close']);
const dialog = ref(null), versions = ref([]), label = ref(''), busy = ref(false), error = ref(''), notice = ref('');
const revision = ref(null);
const load = async () => {
    const [project, list] = await Promise.all([axios.get(`/projects/${props.projectId}`), axios.get(`/projects/${props.projectId}/versions`)]);
    revision.value = project.data.data.revision;
    versions.value = list.data.data;
};
const perform = async action => {
    busy.value = true; error.value = ''; notice.value = '';
    try { await action(); } catch (failure) {
        error.value = failure.response?.status === 409 ? 'Проект изменился. Закройте и откройте список версий заново.' : Object.values(failure.response?.data?.errors ?? {}).flat().join(' ') || failure.response?.data?.message || failure.message;
    } finally { busy.value = false; }
};
const capture = () => perform(async () => {
    await axios.post(`/projects/${props.projectId}/versions`, { label: label.value.trim() || 'Сохранённая версия', expected_revision: revision.value });
    await load(); label.value = ''; notice.value = 'Версия сохранена.';
});
const restore = version => {
    if (!window.confirm(`Восстановить «${version.label}»? Текущая сборка будет заменена, история Ctrl+Z очищена. Перед заменой сохранится резервная версия.`)) return;
    perform(async () => {
        await axios.post(`/projects/${props.projectId}/versions/${version.id}/restore`, { expected_revision: revision.value });
        await props.onRestored();
        await load(); notice.value = 'Версия восстановлена. Предыдущее состояние доступно в списке.';
    });
};
const close = () => { if (!busy.value) { dialog.value.close(); emit('close'); } };
onMounted(() => { dialog.value.showModal(); perform(load); });
</script>
