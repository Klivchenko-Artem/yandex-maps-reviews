<script setup lang="ts">
import { describeError } from '@/api/http';
import ErrorAlert from '@/components/ErrorAlert.vue';
import { useAuth } from '@/composables/useAuth';
import { reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

const { login } = useAuth();
const router = useRouter();
const route = useRoute();

const form = reactive({ email: '', password: '', remember: true });
const submitting = ref(false);
const error = ref<string | null>(null);
const fieldErrors = ref<Record<string, string>>({});

/** Возвращаемся только на свои страницы: ?redirect=//evil.com не должен никуда увести. */
function safeRedirect(): string {
    const target = route.query.redirect;
    return typeof target === 'string' && target.startsWith('/') && !target.startsWith('//') ? target : '/settings';
}

async function submit(): Promise<void> {
    submitting.value = true;
    error.value = null;
    fieldErrors.value = {};

    try {
        await login(form.email.trim(), form.password, form.remember);
        await router.replace(safeRedirect());
    } catch (e) {
        const info = describeError(e);
        fieldErrors.value = info.fields;
        error.value = Object.keys(info.fields).length ? null : info.message;
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <div class="mx-auto mt-16 max-w-sm">
        <div class="mb-6 text-center">
            <div class="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-xl bg-amber-400 text-2xl">★</div>
            <h1 class="text-2xl font-semibold">Вход</h1>
            <p class="mt-1 text-sm text-slate-500">Отзывы и рейтинг организаций с Яндекс.Карт</p>
        </div>

        <form class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm" novalidate @submit.prevent="submit">
            <ErrorAlert v-if="error" :message="error" />

            <label class="block">
                <span class="mb-1 block text-sm font-medium">Логин (email)</span>
                <input
                    v-model="form.email"
                    type="email"
                    autocomplete="username"
                    required
                    class="w-full rounded-md border px-3 py-2 outline-none focus:ring-2 focus:ring-amber-400"
                    :class="fieldErrors.email ? 'border-red-400' : 'border-slate-300'"
                />
                <span v-if="fieldErrors.email" class="mt-1 block text-sm text-red-600">{{ fieldErrors.email }}</span>
            </label>

            <label class="block">
                <span class="mb-1 block text-sm font-medium">Пароль</span>
                <input
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    required
                    class="w-full rounded-md border px-3 py-2 outline-none focus:ring-2 focus:ring-amber-400"
                    :class="fieldErrors.password ? 'border-red-400' : 'border-slate-300'"
                />
                <span v-if="fieldErrors.password" class="mt-1 block text-sm text-red-600">{{ fieldErrors.password }}</span>
            </label>

            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input v-model="form.remember" type="checkbox" class="rounded" />
                Запомнить меня
            </label>

            <button
                type="submit"
                class="w-full rounded-md bg-slate-900 px-4 py-2 font-medium text-white hover:bg-slate-700 disabled:opacity-60"
                :disabled="submitting"
            >
                {{ submitting ? 'Входим…' : 'Войти' }}
            </button>
        </form>
    </div>
</template>
