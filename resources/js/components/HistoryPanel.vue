<script setup lang="ts">
import { organizationsApi } from '@/api';
import { describeError } from '@/api/http';
import type { History } from '@/api/types';
import { formatDate, formatRating } from '@/utils/format';
import { ref } from 'vue';
import ErrorAlert from './ErrorAlert.vue';

const props = defineProps<{ organizationId: number }>();

const history = ref<History | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
const open = ref(false);

const FIELD_LABELS: Record<string, string> = {
    name: 'Название',
    address: 'Адрес',
    rating: 'Рейтинг',
    ratings_count: 'Оценок',
    reviews_count: 'Отзывов',
    text: 'Текст',
    author_name: 'Автор',
    business_reply: 'Ответ организации',
    removed_at: 'Удалён',
};

const EVENT_LABELS = { changed: 'изменён', removed: 'пропал с карт', restored: 'вернулся' } as const;

function show(value: unknown, field: string): string {
    if (value === null || value === undefined || value === '') {
        return '-';
    }
    if (field === 'rating' && typeof value === 'number') {
        return formatRating(value);
    }
    const text = String(value);
    return text.length > 80 ? `${text.slice(0, 80)}…` : text;
}

async function toggle(): Promise<void> {
    open.value = !open.value;
    if (open.value) {
        await load();
    }
}

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        history.value = await organizationsApi.history(props.organizationId);
    } catch (e) {
        error.value = describeError(e).message;
    } finally {
        loading.value = false;
    }
}

defineExpose({ reload: () => open.value && load() });
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white">
        <button type="button" class="flex w-full items-center justify-between px-4 py-3 text-left font-medium" @click="toggle">
            История изменений между парсингами
            <span class="text-slate-400">{{ open ? '▲' : '▼' }}</span>
        </button>

        <div v-if="open" class="border-t border-slate-200 px-4 py-3 text-sm">
            <ErrorAlert v-if="error" :message="error" retryable @retry="load" />
            <p v-else-if="loading && !history" class="text-slate-500">Загружаем…</p>

            <template v-else-if="history">
                <p v-if="history.snapshots.length === 0" class="text-slate-500">Ещё не было ни одного успешного парсинга.</p>

                <ul class="space-y-3">
                    <li v-for="snapshot in history.snapshots" :key="snapshot.captured_at" class="border-l-2 border-slate-200 pl-3">
                        <p class="font-medium">{{ formatDate(snapshot.captured_at, true) }}</p>
                        <p v-if="snapshot.is_first" class="text-slate-500">Первый снимок: ★ {{ formatRating(snapshot.rating) }}, {{ snapshot.ratings_count }} оценок, {{ snapshot.reviews_count }} отзывов</p>
                        <template v-else>
                            <p v-for="(change, field) in snapshot.changes" :key="field">
                                {{ FIELD_LABELS[field] ?? field }}: <span class="text-slate-500 line-through">{{ show(change?.old, field) }}</span> →
                                <b>{{ show(change?.new, field) }}</b>
                            </p>
                            <p v-if="Object.keys(snapshot.changes).length === 0" class="text-slate-500">Карточка без изменений</p>
                        </template>
                        <p class="text-slate-500">
                            Отзывы: новых {{ snapshot.reviews_created ?? 0 }}, изменённых {{ snapshot.reviews_updated ?? 0 }}, пропавших {{ snapshot.reviews_removed ?? 0 }}
                        </p>
                    </li>
                </ul>

                <template v-if="history.review_revisions.length">
                    <h3 class="mt-5 mb-2 font-medium">Последние изменения отзывов</h3>
                    <ul class="space-y-2">
                        <li v-for="revision in history.review_revisions" :key="`${revision.review_id}-${revision.created_at}-${revision.event}`">
                            <span class="text-slate-500">{{ formatDate(revision.created_at, true) }}</span> -
                            отзыв {{ revision.author_name }} {{ EVENT_LABELS[revision.event] }}
                            <template v-if="revision.event === 'changed'">
                                <span v-for="(change, field) in revision.changes" :key="field" class="block pl-4 text-slate-600">
                                    {{ FIELD_LABELS[field] ?? field }}: <span class="line-through">{{ show(change.old, String(field)) }}</span> →
                                    {{ show(change.new, String(field)) }}
                                </span>
                            </template>
                        </li>
                    </ul>
                </template>
            </template>
        </div>
    </section>
</template>
