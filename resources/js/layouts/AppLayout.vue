<template>
    <div class="min-h-screen bg-stone-100 text-stone-950">
        <header class="border-b border-stone-200 bg-white/95 backdrop-blur">
            <div class="mx-auto flex h-18 max-w-7xl items-center justify-between gap-6 px-5 lg:px-8">
                <router-link :to="{ name: 'projects' }" class="flex shrink-0 items-center gap-3">
                    <span class="brand-mark">W</span>
                    <span class="hidden sm:block">
                        <strong class="block text-[15px] tracking-tight">Woodwork</strong>
                        <span class="block text-[10px] font-semibold tracking-[0.2em] text-stone-400 uppercase">3D Studio</span>
                    </span>
                </router-link>

                <nav class="flex items-center gap-1 rounded-xl bg-stone-100 p-1" aria-label="Главное меню">
                    <router-link
                        :to="{ name: 'projects' }"
                        class="rounded-lg px-2 py-2 text-xs font-semibold text-stone-500 transition hover:text-emerald-900 sm:px-3"
                        active-class="bg-white text-emerald-900 shadow-sm"
                    >Проекты</router-link>
                    <router-link
                        :to="{ name: 'api-tokens' }"
                        class="rounded-lg px-2 py-2 text-xs font-semibold text-stone-500 transition hover:text-emerald-900 sm:px-3"
                        active-class="bg-white text-emerald-900 shadow-sm"
                    >API-ключи</router-link>
                </nav>

                <div class="flex items-center gap-3">
                    <span class="hidden text-sm text-stone-500 sm:block">{{ auth.user?.name }}</span>
                    <button class="button-secondary" type="button" @click="logout">Выйти</button>
                </div>
            </div>
        </header>

        <main>
            <slot />
        </main>
    </div>
</template>

<script setup>
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const router = useRouter();

const logout = async () => {
    await auth.logout();
    await router.push({ name: 'login' });
};
</script>
