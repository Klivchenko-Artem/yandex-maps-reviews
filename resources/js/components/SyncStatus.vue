<script setup lang="ts">
import type { SyncRun } from '@/api/types';
import { formatDate } from '@/utils/format';
import { computed } from 'vue';

const props = defineProps<{ run: SyncRun | null; compact?: boolean }>();

const LABELS: Record<SyncRun['status'], string> = {
    queued: 'В очереди',
    running: 'Собираем отзывы',
    retrying: 'Ждём повтора',
    completed: 'Данные актуальны',
    failed: 'Ошибка',
    source_changed: 'Парсер не узнал ответ Яндекса',
};

const TONES: Record<SyncRun['status'], string> = {
    queued: 'bg-slate-100 text-slate-700',
    running: 'bg-blue-100 text-blue-800',
    retrying: 'bg-amber-100 text-amber-800',
    completed: 'bg-emerald-100 text-emerald-800',
    failed: 'bg-red-100 text-red-800',
    source_changed: 'bg-red-100 text-red-800',
};

const pagesText = computed(() => {
    const run = props.run;
    if (!run?.pages_total) {
        return null;
    }
    return `страница ${run.pages_done} из ${run.pages_total}, получено ${run.reviews_fetched}`;
});
</script>

<template>
    <div v-if="run" class="text-sm">
        <div class="flex flex-wrap items-center gap-2">
            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 font-medium" :class="TONES[run.status]">
                <span v-if="run.is_active" class="h-2 w-2 animate-pulse rounded-full bg-current" />
                {{ LABELS[run.status] }}
            </span>
            <span v-if="run.is_active && pagesText" class="text-slate-500">{{ pagesText }}</span>
            <span v-else-if="run.status === 'completed' && !compact" class="text-slate-500">
                обновлено {{ formatDate(run.finished_at, true) }}
            </span>
        </div>

        <div v-if="run.is_active && !compact" class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200">
            <div class="h-full rounded-full bg-blue-500 transition-all duration-500" :style="{ width: `${Math.max(run.progress, 3)}%` }" />
        </div>

        <p v-if="run.error && (!compact || !run.is_active)" class="mt-2 text-slate-600" :class="{ 'mt-1 text-xs': compact }">
            {{ run.error.message }}
        </p>
    </div>
</template>
