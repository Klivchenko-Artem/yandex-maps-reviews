<script setup lang="ts">
import { organizationsApi } from '@/api';
import { describeError } from '@/api/http';
import type { Organization } from '@/api/types';
import ErrorAlert from '@/components/ErrorAlert.vue';
import SyncStatus from '@/components/SyncStatus.vue';
import { formatCount, formatRating } from '@/utils/format';
import { looksLikeOrganizationLink } from '@/utils/organizationLink';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';

const router = useRouter();

const url = ref('');
const saving = ref(false);
const urlError = ref<string | null>(null);
const formError = ref<string | null>(null);

const organizations = ref<Organization[]>([]);
const listLoading = ref(true);
const listError = ref<string | null>(null);
let pollTimer: ReturnType<typeof setTimeout> | null = null;

const looksValid = computed(() => looksLikeOrganizationLink(url.value));

// Номер запроса: ответ опроса, ушедшего до «Отключить», не должен вернуть удалённую организацию в список.
let listSeq = 0;
let disposed = false;

async function loadList(silent = false): Promise<void> {
    const seq = ++listSeq;
    if (!silent) {
        listLoading.value = true;
    }
    try {
        const list = await organizationsApi.list();
        if (seq === listSeq) {
            organizations.value = list;
            listError.value = null;
        }
    } catch (e) {
        // Сбой фонового опроса не прячет уже показанный список: следующий опрос, скорее всего, пройдёт.
        if (seq === listSeq && !silent) {
            listError.value = describeError(e).message;
        }
    } finally {
        if (seq === listSeq) {
            listLoading.value = false;
            schedulePoll();
        }
    }
}

/** Пока хоть одна организация парсится, обновляем список, чтобы статус и прогресс были живыми. */
function stopPoll(): void {
    if (pollTimer !== null) {
        clearTimeout(pollTimer);
        pollTimer = null;
    }
}

function schedulePoll(): void {
    stopPoll();
    if (!disposed && organizations.value.some((o) => o.sync?.is_active)) {
        pollTimer = setTimeout(() => loadList(true), 3000);
    }
}

async function save(): Promise<void> {
    urlError.value = null;
    formError.value = null;

    if (!url.value.trim()) {
        urlError.value = 'Вставьте ссылку на карточку организации';
        return;
    }
    if (!looksValid.value) {
        urlError.value = 'Нужна ссылка на Яндекс.Карты, например https://yandex.ru/maps/org/название/1234567890/';
        return;
    }

    saving.value = true;
    try {
        const organization = await organizationsApi.create(url.value.trim());
        url.value = '';
        await router.push({ name: 'organization', params: { id: organization.id } });
    } catch (e) {
        const info = describeError(e);
        if (info.fields.url) {
            urlError.value = info.fields.url;
        } else {
            formError.value = info.message;
        }
    } finally {
        saving.value = false;
    }
}

async function remove(organization: Organization): Promise<void> {
    if (!confirm(`Отключить «${organization.name ?? organization.url}»? Сохранённые отзывы и история удалятся.`)) {
        return;
    }
    try {
        await organizationsApi.remove(organization.id);
        listSeq++;
        organizations.value = organizations.value.filter((o) => o.id !== organization.id);
        schedulePoll();
    } catch (e) {
        listError.value = describeError(e).message;
    }
}

onMounted(() => loadList());
onBeforeUnmount(() => {
    disposed = true;
    listSeq++;
    stopPoll();
});
</script>

<template>
    <div class="space-y-8">
        <section>
            <h1 class="text-2xl font-semibold">Настройки</h1>
            <p class="mt-1 text-slate-500">Подключите карточку организации на Яндекс.Картах, и мы соберём отзывы, рейтинг и счётчики.</p>

            <form class="mt-5 rounded-xl border border-slate-200 bg-white p-5 shadow-sm" novalidate @submit.prevent="save">
                <label for="org-url" class="mb-1 block text-sm font-medium">Ссылка на карточку организации</label>
                <div class="flex flex-col gap-3 sm:flex-row">
                    <input
                        id="org-url"
                        v-model="url"
                        type="url"
                        inputmode="url"
                        placeholder="https://yandex.ru/maps/org/..."
                        class="min-w-0 flex-1 rounded-md border px-3 py-2 outline-none focus:ring-2 focus:ring-amber-400"
                        :class="urlError ? 'border-red-400' : 'border-slate-300'"
                        :disabled="saving"
                        @input="urlError = null"
                    />
                    <button
                        type="submit"
                        class="rounded-md bg-slate-900 px-5 py-2 font-medium text-white hover:bg-slate-700 disabled:opacity-60"
                        :disabled="saving"
                    >
                        {{ saving ? 'Сохраняем…' : 'Сохранить и загрузить' }}
                    </button>
                </div>
                <p v-if="urlError" class="mt-2 text-sm text-red-600">{{ urlError }}</p>
                <p v-else class="mt-2 text-xs text-slate-500">
                    Подойдёт ссылка из адресной строки или короткая из кнопки «Поделиться» (yandex.ru/maps/-/…).
                </p>
                <ErrorAlert v-if="formError" class="mt-3" :message="formError" />
            </form>
        </section>

        <section>
            <h2 class="mb-3 text-lg font-semibold">Подключённые организации</h2>

            <ErrorAlert v-if="listError" :message="listError" retryable @retry="loadList()" />

            <div v-else-if="listLoading" class="space-y-2">
                <div v-for="i in 2" :key="i" class="h-20 animate-pulse rounded-xl bg-slate-200" />
            </div>

            <p v-else-if="organizations.length === 0" class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-slate-500">
                Пока ничего не подключено.
            </p>

            <ul v-else class="space-y-2">
                <li
                    v-for="organization in organizations"
                    :key="organization.id"
                    class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="min-w-0">
                        <RouterLink
                            :to="{ name: 'organization', params: { id: organization.id } }"
                            class="font-medium break-words hover:underline"
                        >
                            {{ organization.name ?? `Организация ${organization.external_id}` }}
                        </RouterLink>
                        <p v-if="organization.address" class="text-sm break-words text-slate-500">{{ organization.address }}</p>
                        <p v-if="organization.last_synced_at" class="mt-1 text-sm text-slate-600">
                            ★ {{ formatRating(organization.rating) }} ·
                            {{ formatCount(organization.ratings_count, ['оценка', 'оценки', 'оценок']) }} ·
                            {{ formatCount(organization.reviews_count, ['отзыв', 'отзыва', 'отзывов']) }}
                        </p>
                        <SyncStatus class="mt-2" :run="organization.sync" compact />
                    </div>
                    <div class="flex shrink-0 gap-2">
                        <RouterLink
                            :to="{ name: 'organization', params: { id: organization.id } }"
                            class="rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50"
                        >
                            Открыть
                        </RouterLink>
                        <button
                            type="button"
                            class="rounded-md px-3 py-1.5 text-sm text-red-600 hover:bg-red-50"
                            @click="remove(organization)"
                        >
                            Отключить
                        </button>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>
