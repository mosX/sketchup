<template>
    <main class="auth-shell">
        <section class="auth-story">
            <div class="relative z-10 max-w-xl">
                <div class="mb-14 flex items-center gap-3">
                    <span class="brand-mark brand-mark-light">W</span>
                    <span class="font-semibold tracking-tight">Woodwork 3D Studio</span>
                </div>
                <p class="mb-4 text-xs font-bold tracking-[0.24em] text-lime-300 uppercase">Проектируйте точно</p>
                <h1 class="text-4xl leading-tight font-semibold tracking-[-0.04em] sm:text-6xl">
                    Идея превращается в изделие.
                </h1>
                <p class="mt-6 max-w-md text-base leading-7 text-emerald-100/70">
                    Храните проекты мебели, открывайте рабочую сцену и шаг за шагом собирайте будущую конструкцию.
                </p>
            </div>
            <div class="grain-orbit" aria-hidden="true"></div>
        </section>

        <section class="auth-form-panel">
            <form class="w-full max-w-md" @submit.prevent="submit">
                <p class="eyebrow">С возвращением</p>
                <h2 class="mt-3 text-3xl font-semibold tracking-tight">Вход в мастерскую</h2>
                <p class="mt-3 text-sm leading-6 text-stone-500">Продолжите работу над сохранёнными проектами.</p>

                <div class="mt-10 grid gap-5">
                    <label class="field-label">
                        Email
                        <input v-model="form.email" class="field-input" type="email" autocomplete="email" required>
                        <span v-if="errors.email" class="field-error">{{ errors.email }}</span>
                    </label>
                    <label class="field-label">
                        Пароль
                        <input v-model="form.password" class="field-input" type="password" autocomplete="current-password" required>
                        <span v-if="errors.password" class="field-error">{{ errors.password }}</span>
                    </label>
                </div>

                <p v-if="errors.general" class="mt-5 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ errors.general }}</p>

                <button class="button-primary mt-7 w-full" type="submit" :disabled="loading">
                    {{ loading ? 'Входим…' : 'Войти' }}
                </button>

                <p class="mt-7 text-center text-sm text-stone-500">
                    Впервые здесь?
                    <router-link class="font-semibold text-emerald-800 hover:text-emerald-600" :to="{ name: 'register' }">Создать аккаунт</router-link>
                </p>
            </form>
        </section>
    </main>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const loading = ref(false);
const form = reactive({ email: '', password: '' });
const errors = reactive({ email: '', password: '', general: '' });

const submit = async () => {
    Object.keys(errors).forEach((key) => { errors[key] = ''; });
    loading.value = true;

    try {
        await auth.login(form);
        await router.push(route.query.redirect || { name: 'projects' });
    } catch (error) {
        const apiErrors = error.response?.data?.errors ?? {};
        errors.email = apiErrors.email?.[0] ?? '';
        errors.password = apiErrors.password?.[0] ?? '';
        errors.general = apiErrors.general?.[0] ?? 'Не удалось выполнить вход.';
    } finally {
        loading.value = false;
    }
};
</script>
