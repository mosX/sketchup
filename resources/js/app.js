import axios from 'axios';
import { createApp } from 'vue';
import { createPinia } from 'pinia';

import App from './App.vue';
import { router } from './router';
import { useAuthStore } from './stores/auth';

axios.defaults.baseURL = '/api';
axios.defaults.headers.common.Accept = 'application/json';

const pinia = createPinia();
const app = createApp(App);

app.use(pinia);
app.use(router);

axios.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 401) {
            useAuthStore().clearSession();

            if (router.currentRoute.value.meta.requiresAuth) {
                router.push({ name: 'login' });
            }
        }

        return Promise.reject(error);
    },
);

app.mount('#app');
