<template>
    <main class="auth-shell">
        <section class="auth-story">
            <div class="relative z-10 max-w-xl">
                <div class="mb-14 flex items-center gap-3">
                    <span class="brand-mark brand-mark-light">W</span>
                    <span class="font-semibold tracking-tight">Woodwork 3D Studio</span>
                </div>
                <p class="mb-4 text-xs font-bold tracking-[0.24em] text-lime-300 uppercase">Новая мастерская</p>
                <h1 class="text-4xl leading-tight font-semibold tracking-[-0.04em] sm:text-6xl">
                    Начните с чистого листа.
                </h1>
                <p class="mt-6 max-w-md text-base leading-7 text-emerald-100/70">
                    Создавайте проекты, сохраняйте сцену и возвращайтесь к работе с любого устройства.
                </p>
            </div>
            <div class="grain-orbit" aria-hidden="true"></div>
        </section>

        <section class="auth-form-panel">
            <form class="w-full max-w-md" @submit.prevent="submit">
                <p class="eyebrow">Регистрация</p>
                <h2 class="mt-3 text-3xl font-semibold tracking-tight">Создать аккаунт</h2>
                <p class="mt-3 text-sm leading-6 text-stone-500">Это займёт меньше минуты.</p>

                <div class="mt-8 grid gap-4">
                    <label class="field-label">Ваше имя<input v-model="form.name" class="field-input" autocomplete="name" required><span v-if="errors.name" class="field-error">{{ errors.name }}</span></label>
                    <label class="field-label">Email<input v-model="form.email" class="field-input" type="email" autocomplete="email" required><span v-if="errors.email" class="field-error">{{ errors.email }}</span></label>
                    <label class="field-label">Пароль<input v-model="form.password" class="field-input" type="password" autocomplete="new-password" minlength="8" required><span v-if="errors.password" class="field-error">{{ errors.password }}</span></label>
                    <label class="field-label">Повторите пароль<input v-model="form.password_confirmation" class="field-input" type="password" autocomplete="new-password" required></label>
                </div>

                <button class="button-primary mt-7 w-full" type="submit" :disabled="loading">
                    {{ loading ? 'Создаём…' : 'Создать аккаунт' }}
                </button>

                <p class="mt-6 text-center text-sm text-stone-500">
                    Уже есть аккаунт?
                    <router-link class="font-semibold text-emerald-800 hover:text-emerald-600" :to="{ name: 'login' }">Войти</router-link>
                </p>
            </form>
        </section>
    </main>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const router = useRouter();
const loading = ref(false);
const form = reactive({ name: '', email: '', password: '', password_confirmation: '' });
const errors = reactive({ name: '', email: '', password: '' });

const submit = async () => {
    Object.keys(errors).forEach((key) => { errors[key] = ''; });
    loading.value = true;

    try {
        await auth.register(form);
        await router.push({ name: 'projects' });
    } catch (error) {
        const apiErrors = error.response?.data?.errors ?? {};
        Object.keys(errors).forEach((key) => { errors[key] = apiErrors[key]?.[0] ?? ''; });
    } finally {
        loading.value = false;
    }
};
</script>
