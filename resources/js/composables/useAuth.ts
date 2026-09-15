import { authApi } from '@/api';
import type { User } from '@/api/types';
import axios from 'axios';
import { computed, readonly, ref } from 'vue';

// Состояние модульное: один пользователь на вкладку, делить через Pinia ради этого незачем.
const user = ref<User | null>(null);
let loaded: Promise<void> | null = null;

export function useAuth() {
    /** Узнаёт, есть ли живая сессия. Запрос уходит один раз, повторные вызовы ждут его же. */
    function loadUser(): Promise<void> {
        loaded ??= authApi
            .me()
            .then((me) => {
                user.value = me;
            })
            .catch((error: unknown) => {
                user.value = null;
                // 401 значит, что просто не вошли. Остальное (сеть, 500) не повод навсегда считать гостем.
                if (!axios.isAxiosError(error) || error.response?.status !== 401) {
                    loaded = null;
                }
            });
        return loaded;
    }

    async function login(email: string, password: string, remember: boolean): Promise<void> {
        user.value = await authApi.login(email, password, remember);
        loaded = Promise.resolve();
    }

    async function logout(): Promise<void> {
        try {
            await authApi.logout();
        } finally {
            forget();
        }
    }

    function forget(): void {
        user.value = null;
        loaded = Promise.resolve();
    }

    return {
        user: readonly(user),
        isAuthenticated: computed(() => user.value !== null),
        loadUser,
        login,
        logout,
        forget,
    };
}
