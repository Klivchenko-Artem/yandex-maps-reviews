<script setup lang="ts">
import { useAuth } from '@/composables/useAuth';
import { ref } from 'vue';
import { useRouter } from 'vue-router';

const { user, isAuthenticated, logout } = useAuth();
const router = useRouter();
const leaving = ref(false);

async function onLogout(): Promise<void> {
    leaving.value = true;
    try {
        await logout();
    } finally {
        leaving.value = false;
        router.push({ name: 'login' });
    }
}
</script>

<template>
    <div class="min-h-screen">
        <header v-if="isAuthenticated" class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-3">
                <RouterLink :to="{ name: 'settings' }" class="flex items-center gap-2 font-semibold">
                    <span class="grid h-7 w-7 place-items-center rounded-md bg-amber-400 text-sm text-slate-900">★</span>
                    Отзывы с карт
                </RouterLink>
                <div class="flex items-center gap-4 text-sm">
                    <span class="hidden text-slate-500 sm:inline">{{ user?.email }}</span>
                    <button
                        type="button"
                        class="rounded-md px-2 py-1 text-slate-600 hover:bg-slate-100 disabled:opacity-50"
                        :disabled="leaving"
                        @click="onLogout"
                    >
                        Выйти
                    </button>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-4 py-6">
            <RouterView />
        </main>
    </div>
</template>
