import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from './stores/auth';

export const router = createRouter({
    history: createWebHistory(),
    routes: [
        {
            path: '/login',
            name: 'login',
            component: () => import('./pages/Login.vue'),
            meta: { title: 'Вход', guestOnly: true },
        },
        {
            path: '/register',
            name: 'register',
            component: () => import('./pages/Register.vue'),
            meta: { title: 'Регистрация', guestOnly: true },
        },
        {
            path: '/',
            name: 'projects',
            component: () => import('./pages/Projects.vue'),
            meta: { title: 'Мои проекты', requiresAuth: true },
        },
        {
            path: '/projects/:id/editor',
            name: 'editor',
            component: () => import('./pages/Editor.vue'),
            meta: { title: 'Редактор', requiresAuth: true },
        },
        { path: '/:pathMatch(.*)*', redirect: '/' },
    ],
});

router.beforeEach(async (to) => {
    const auth = useAuthStore();

    if (auth.token && !auth.user) {
        try {
            await auth.fetchUser();
        } catch {
            auth.clearSession();
        }
    }

    if (to.meta.requiresAuth && !auth.isAuthenticated) {
        return { name: 'login', query: { redirect: to.fullPath } };
    }

    if (to.meta.guestOnly && auth.isAuthenticated) {
        return { name: 'projects' };
    }

    document.title = `${to.meta.title ?? 'Редактор'} · Woodwork`;

    return true;
});
