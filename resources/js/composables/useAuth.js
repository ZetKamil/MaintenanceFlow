import { computed, ref } from 'vue';
import { api } from '@/services/api';

// Initialize immediately from Laravel's Blade shell to avoid auth flickering
const user = ref(window.__INITIAL_STATE__?.user || null);

export function useAuth() {
    const isAuthenticated = computed(() => user.value !== null);
    const isOwner = computed(() => user.value?.role === 'owner');
    const isTechnician = computed(() => user.value?.role === 'technician');

    async function login(email, password) {
        const data = await api.post('/api/login', { email, password });
        user.value = data.user;
        return data.user;
    }

    async function logout() {
        await api.post('/api/logout');
        user.value = null;
    }

    async function refreshUser() {
        try {
            const data = await api.get('/api/me');
            user.value = data.user;
        } catch {
            user.value = null;
        }
    }

    return {
        user,
        isAuthenticated,
        isOwner,
        isTechnician,
        login,
        logout,
        refreshUser,
    };
}