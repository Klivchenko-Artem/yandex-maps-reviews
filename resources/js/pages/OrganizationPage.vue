<script setup lang="ts">
import ErrorAlert from '@/components/ErrorAlert.vue';
import HistoryPanel from '@/components/HistoryPanel.vue';
import PaginationBar from '@/components/PaginationBar.vue';
import ReviewCard from '@/components/ReviewCard.vue';
import StarRating from '@/components/StarRating.vue';
import SyncStatus from '@/components/SyncStatus.vue';
import { useOrganization } from '@/composables/useOrganization';
import { useReviews } from '@/composables/useReviews';
import { organizationsApi } from '@/api';
import { describeError } from '@/api/http';
import { formatCount, formatDate, formatRating } from '@/utils/format';
import { computed, ref, toRef, useTemplateRef, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

const props = defineProps<{ id: number }>();

const route = useRoute();
const router = useRouter();
const history = useTemplateRef<InstanceType<typeof HistoryPanel>>('history');
const listTop = ref<HTMLElement | null>(null);

// Номер страницы живёт в адресе (?page=3): переключение без перезагрузки, но ссылкой можно поделиться.
const page = computed({
    get: () => {
        const n = Number(route.query.page ?? 1);
        return Number.isInteger(n) && n > 0 ? n : 1;
    },
    set: (value: number) => {
        router.push({ query: { ...route.query, page: value > 1 ? String(value) : undefined } });
    },
});

const id = toRef(props, 'id');
const { reviews, meta, loading: reviewsLoading, error: reviewsError, reload: reloadReviews } = useReviews(id, page);
const { organization, loading, error, reload, resync } = useOrganization(
    id,
    () => {
        reloadReviews(true);
        history.value?.reload();
    },
    // Пока идёт парсинг, отзывы подтягиваются по мере скачивания: без мигания и без сброса текущей страницы.
    () => reloadReviews(true),
);

// Страниц стало меньше (часть отзывов пропала после перепарсинга) или в адресе ?page=99 -
// переходим на последнюю существующую, а не показываем пустоту с неработающей кнопкой «Назад».
watch(meta, (value) => {
    if (value && value.last_page >= 1 && value.current_page > value.last_page) {
        router.replace({ query: { ...route.query, page: value.last_page > 1 ? String(value.last_page) : undefined } });
    }
});

const notFound = computed(() => organization.value?.sync?.error?.code === 'not_found');

// Заголовок до первого успешного парсинга зависит от того, чем кончилась загрузка:
// «Загружаем…» рядом со статусом «Ошибка» выглядит как зависание.
const title = computed(() => {
    const current = organization.value;
    if (current?.name) {
        return current.name;
    }
    if (notFound.value) {
        return 'Организация не найдена на Яндекс.Картах';
    }
    return current?.sync?.is_active ? 'Загружаем данные организации…' : 'Не удалось загрузить данные организации';
});

async function removeMissing(): Promise<void> {
    if (!organization.value || !confirm('Удалить эту ссылку из подключённых?')) {
        return;
    }
    try {
        await organizationsApi.remove(organization.value.id);
        await router.push({ name: 'settings' });
    } catch (e) {
        alert(describeError(e).message);
    }
}

const syncing = computed(() => organization.value?.sync?.is_active ?? false);
const neverSynced = computed(() => organization.value !== null && organization.value.last_synced_at === null);

function changePage(value: number): void {
    page.value = value;
    listTop.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
</script>

<template>
    <div class="space-y-6">
        <RouterLink :to="{ name: 'settings' }" class="text-sm text-slate-500 hover:underline">← Все организации</RouterLink>

        <ErrorAlert v-if="error && !organization" :message="error" retryable @retry="reload()" />

        <div v-else-if="loading && !organization" class="h-40 animate-pulse rounded-xl bg-slate-200" />

        <template v-else-if="organization">
            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <h1 class="text-2xl font-semibold break-words">
                            {{ title }}
                        </h1>
                        <p v-if="organization.address" class="text-slate-500">{{ organization.address }}</p>
                        <a :href="organization.url" target="_blank" rel="noopener noreferrer" class="mt-1 inline-block text-sm break-all text-blue-600 hover:underline">
                            Открыть на Яндекс.Картах ↗
                        </a>
                    </div>
                    <button
                        type="button"
                        class="shrink-0 rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50 disabled:opacity-50"
                        :disabled="syncing"
                        @click="resync"
                    >
                        {{ syncing ? 'Обновляется…' : 'Обновить данные' }}
                    </button>
                </div>

                <div v-if="!neverSynced" class="mt-5 grid gap-4 sm:grid-cols-3">
                    <div class="rounded-lg bg-slate-50 p-4">
                        <p class="text-sm text-slate-500">Средний рейтинг</p>
                        <p class="mt-1 flex items-center gap-2 text-3xl font-semibold">
                            {{ formatRating(organization.rating) }}
                            <StarRating :value="organization.rating" size="lg" />
                        </p>
                    </div>
                    <div class="rounded-lg bg-slate-50 p-4">
                        <p class="text-sm text-slate-500">Оценок</p>
                        <p class="mt-1 text-3xl font-semibold">{{ organization.ratings_count.toLocaleString('ru-RU') }}</p>
                    </div>
                    <div class="rounded-lg bg-slate-50 p-4">
                        <p class="text-sm text-slate-500">Отзывов</p>
                        <p class="mt-1 text-3xl font-semibold">{{ organization.reviews_count.toLocaleString('ru-RU') }}</p>
                    </div>
                </div>

                <div class="mt-4">
                    <SyncStatus :run="organization.sync" />
                    <div v-if="notFound && neverSynced" class="mt-3 flex flex-wrap items-center gap-3 text-sm text-slate-600">
                        Проверьте ссылку: карточка могла быть удалена или ссылка ведёт не на организацию.
                        <button type="button" class="rounded-md border border-red-300 px-3 py-1 text-red-700 hover:bg-red-50" @click="removeMissing">
                            Удалить из подключённых
                        </button>
                    </div>
                    <ErrorAlert v-if="error" class="mt-2" :message="error" />
                    <p v-if="organization.sync?.status === 'source_changed'" class="mt-2 text-sm text-slate-600">
                        Данные ниже с последнего успешного парсинга{{ organization.last_synced_at ? ` (${formatDate(organization.last_synced_at, true)})` : '' }}.
                        Ошибка записана в лог, парсер нужно поправить.
                    </p>
                </div>
            </section>

            <HistoryPanel v-if="!neverSynced" ref="history" :organization-id="organization.id" />

            <section ref="listTop" class="scroll-mt-4">
                <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-lg font-semibold">Отзывы</h2>
                    <p v-if="meta && meta.total > 0" class="text-sm text-slate-500">
                        {{ meta.from }}-{{ meta.to }} из {{ formatCount(meta.total, ['отзыва', 'отзывов', 'отзывов']) }} в базе
                        <template v-if="organization.reviews_count > meta.total"> · Яндекс отдаёт последние ~600</template>
                    </p>
                </div>

                <ErrorAlert v-if="reviewsError" :message="reviewsError" retryable @retry="reloadReviews()" />

                <template v-else>
                    <div v-if="reviewsLoading && reviews.length === 0" class="space-y-3">
                        <div v-for="i in 3" :key="i" class="h-28 animate-pulse rounded-xl bg-slate-200" />
                    </div>

                    <p v-else-if="reviews.length === 0" class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-slate-500">
                        {{ syncing ? 'Загружаем отзывы с Яндекс.Карт, первые появятся через несколько секунд…' : 'Отзывов пока нет.' }}
                    </p>

                    <div v-else class="space-y-3 transition-opacity" :class="{ 'opacity-50': reviewsLoading }" :aria-busy="reviewsLoading">
                        <ReviewCard v-for="review in reviews" :key="review.id" :review="review" />
                    </div>
                </template>

                <PaginationBar
                    v-if="meta"
                    class="mt-6"
                    :current="meta.current_page"
                    :last="meta.last_page"
                    :disabled="reviewsLoading"
                    @change="changePage"
                />
            </section>
        </template>
    </div>
</template>
