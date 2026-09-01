<template>
    <AppLayout>
        <div class="mx-auto max-w-5xl px-5 py-10 lg:px-8 lg:py-14">
            <div class="flex flex-col justify-between gap-6 sm:flex-row sm:items-end">
                <div>
                    <p class="eyebrow">Интеграции</p>
                    <h1 class="mt-3 text-3xl font-semibold tracking-[-0.03em] sm:text-4xl">API-ключи</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-stone-500">Глобальный ключ работает со всеми вашими текущими и будущими проектами через API v1 и MCP-сервер.</p>
                </div>
                <router-link class="button-secondary" :to="{ name: 'projects' }">← К проектам</router-link>
            </div>

            <section class="mt-8 overflow-hidden rounded-2xl border border-emerald-900/10 bg-white shadow-sm">
                <div class="grid gap-6 bg-emerald-950 p-6 text-white lg:grid-cols-[minmax(0,1fr)_18rem] lg:items-end">
                    <form class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_9rem_auto] sm:items-end" @submit.prevent="createApiToken">
                        <label class="field-label text-emerald-50">Название ключа<input v-model="apiTokenName" class="field-input field-input-dark" maxlength="100" placeholder="Например, главный AI-агент" required></label>
                        <label class="field-label text-emerald-50">Срок, дней<input v-model.number="expiresInDays" class="field-input field-input-dark" type="number" min="1" max="365" required></label>
                        <button class="button-accent h-12" type="submit" :disabled="loading">{{ loading ? 'Создаём…' : 'Создать ключ' }}</button>
                    </form>
                    <div class="rounded-xl border border-white/10 bg-white/5 p-4 text-xs leading-5 text-emerald-100">
                        <strong class="block text-white">Доступ ко всем проектам</strong>
                        Чтение, создание и изменение проектов. Управлять другими ключами такой токен не сможет.
                    </div>
                </div>

                <div v-if="createdApiToken" class="border-b border-emerald-200 bg-emerald-50 p-6">
                    <div class="flex flex-col justify-between gap-2 sm:flex-row sm:items-center">
                        <strong class="text-sm text-emerald-950">Скопируйте ключ сейчас</strong>
                        <span class="text-xs font-semibold text-emerald-700">После закрытия страницы он больше не показывается</span>
                    </div>
                    <textarea class="mt-3 h-24 w-full resize-none rounded-xl border border-emerald-200 bg-white p-3 font-mono text-xs text-stone-700 outline-none" readonly :value="createdApiToken"></textarea>
                    <button class="button-primary mt-3" type="button" @click="copyApiToken">{{ tokenCopied ? 'Скопировано' : 'Скопировать ключ' }}</button>
                </div>

                <p v-if="errorMessage" class="m-6 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>

                <div class="p-6">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold">Активные ключи</h2>
                            <p class="mt-1 text-xs text-stone-400">Здесь также видны ключи, созданные внутри отдельных проектов.</p>
                        </div>
                        <span class="rounded-full bg-stone-100 px-3 py-1 text-xs font-bold text-stone-500">{{ apiTokens.length }}</span>
                    </div>

                    <div v-if="apiTokens.length" class="mt-5 grid gap-3">
                        <article v-for="token in apiTokens" :key="token.id" class="flex flex-col gap-3 rounded-xl border border-stone-200 p-4 sm:flex-row sm:items-center">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <strong class="truncate text-sm text-stone-800">{{ token.name }}</strong>
                                    <span class="rounded-full px-2 py-1 text-[10px] font-bold" :class="isGlobalToken(token) ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'">{{ tokenScopeLabel(token) }}</span>
                                </div>
                                <p class="mt-2 text-xs text-stone-400">До {{ formatApiTokenDate(token.expires_at) }} · {{ token.abilities.includes('projects:write') ? 'чтение и изменение' : 'только чтение' }}</p>
                            </div>
                            <button class="rounded-lg border border-red-200 px-3 py-2 text-xs font-semibold text-red-600 hover:bg-red-50" type="button" @click="revokeApiToken(token)">Отозвать</button>
                        </article>
                    </div>
                    <div v-else-if="!loading" class="mt-5 rounded-xl border border-dashed border-stone-200 p-8 text-center text-sm text-stone-400">API-ключей пока нет.</div>
                    <div v-else class="mt-5 h-24 animate-pulse rounded-xl bg-stone-100"></div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>

<script setup>
import axios from 'axios';
import { onMounted, ref } from 'vue';
import AppLayout from '../layouts/AppLayout.vue';

const apiTokens = ref([]);
const apiTokenName = ref('Главный AI-агент');
const expiresInDays = ref(30);
const createdApiToken = ref('');
const tokenCopied = ref(false);
const errorMessage = ref('');
const loading = ref(false);

const loadApiTokens = async () => {
    loading.value = true;
    errorMessage.value = '';

    try {
        const { data } = await axios.get('/v1/api-tokens');
        apiTokens.value = data.data;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Не удалось загрузить API-ключи.';
    } finally {
        loading.value = false;
    }
};

const createApiToken = async () => {
    loading.value = true;
    errorMessage.value = '';
    tokenCopied.value = false;

    try {
        const { data } = await axios.post('/v1/api-tokens', {
            name: apiTokenName.value,
            abilities: ['projects:read', 'projects:write'],
            expires_in_days: expiresInDays.value,
        });
        createdApiToken.value = data.token;
        await loadApiTokens();
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Не удалось создать глобальный API-ключ.';
        loading.value = false;
    }
};

const copyApiToken = async () => {
    await navigator.clipboard.writeText(createdApiToken.value);
    tokenCopied.value = true;
};

const revokeApiToken = async (token) => {
    if (!window.confirm(`Отозвать ключ «${token.name}»?`)) return;

    errorMessage.value = '';

    try {
        await axios.delete(`/v1/api-tokens/${token.id}`);
        apiTokens.value = apiTokens.value.filter((item) => item.id !== token.id);
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Не удалось отозвать API-ключ.';
    }
};

const projectAbility = (token) => token.abilities.find((ability) => ability.startsWith('project:'));
const isGlobalToken = (token) => !projectAbility(token);
const tokenScopeLabel = (token) => projectAbility(token)?.replace('project:', 'Проект #') ?? 'Все проекты';
const formatApiTokenDate = (date) => date ? new Intl.DateTimeFormat('ru-UA', { dateStyle: 'medium' }).format(new Date(date)) : 'без ограничения';

onMounted(loadApiTokens);
</script>
