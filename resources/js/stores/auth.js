import axios from 'axios';
import { defineStore } from 'pinia';

const storedToken = localStorage.getItem('woodwork_token');

if (storedToken) {
    axios.defaults.headers.common.Authorization = `Bearer ${storedToken}`;
}

export const useAuthStore = defineStore('auth', {
    state: () => ({
        token: storedToken,
        user: null,
    }),

    getters: {
        isAuthenticated: (state) => Boolean(state.token),
    },

    actions: {
        setSession(payload) {
            this.token = payload.token;
            this.user = payload.data;
            localStorage.setItem('woodwork_token', payload.token);
            axios.defaults.headers.common.Authorization = `Bearer ${payload.token}`;
        },

        clearSession() {
            this.token = null;
            this.user = null;
            localStorage.removeItem('woodwork_token');
            delete axios.defaults.headers.common.Authorization;
        },

        async login(credentials) {
            const { data } = await axios.post('/login', credentials);
            this.setSession(data);
        },

        async register(payload) {
            const { data } = await axios.post('/register', payload);
            this.setSession(data);
        },

        async fetchUser() {
            const { data } = await axios.get('/me');
            this.user = data.data;
        },

        async logout() {
            try {
                await axios.post('/logout');
            } finally {
                this.clearSession();
            }
        },
    },
});
