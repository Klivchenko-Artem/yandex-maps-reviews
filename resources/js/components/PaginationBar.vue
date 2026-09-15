<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ current: number; last: number; disabled?: boolean }>();
const emit = defineEmits<{ change: [page: number] }>();

type Item = { type: 'page'; page: number } | { type: 'gap'; key: string };

/** Первая, последняя и соседи текущей; остальное схлопывается в «…». */
const items = computed<Item[]>(() => {
    const pages = new Set([1, props.last, props.current - 1, props.current, props.current + 1]);
    const sorted = [...pages].filter((p) => p >= 1 && p <= props.last).sort((a, b) => a - b);

    const result: Item[] = [];
    sorted.forEach((page, i) => {
        const prev = sorted[i - 1];
        if (prev !== undefined && page - prev > 1) {
            result.push(page - prev === 2 ? { type: 'page', page: prev + 1 } : { type: 'gap', key: `gap-${prev}` });
        }
        result.push({ type: 'page', page });
    });
    return result;
});

function go(page: number): void {
    if (!props.disabled && page >= 1 && page <= props.last && page !== props.current) {
        emit('change', page);
    }
}
</script>

<template>
    <nav v-if="last > 1" class="flex flex-wrap items-center justify-center gap-1" aria-label="Страницы отзывов">
        <button
            type="button"
            class="rounded-md px-3 py-1.5 text-sm hover:bg-slate-200 disabled:opacity-40"
            :disabled="disabled || current <= 1"
            @click="go(current - 1)"
        >
            ← Назад
        </button>
        <template v-for="item in items" :key="item.type === 'page' ? item.page : item.key">
            <span v-if="item.type === 'gap'" class="px-2 text-slate-400">…</span>
            <button
                v-else
                type="button"
                class="min-w-9 rounded-md px-2 py-1.5 text-sm"
                :class="item.page === current ? 'bg-slate-900 font-medium text-white' : 'hover:bg-slate-200'"
                :aria-current="item.page === current ? 'page' : undefined"
                :disabled="disabled"
                @click="go(item.page)"
            >
                {{ item.page }}
            </button>
        </template>
        <button
            type="button"
            class="rounded-md px-3 py-1.5 text-sm hover:bg-slate-200 disabled:opacity-40"
            :disabled="disabled || current >= last"
            @click="go(current + 1)"
        >
            Вперёд →
        </button>
    </nav>
</template>
