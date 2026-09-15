<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{ value: number | null; size?: 'sm' | 'lg' }>(), { size: 'sm' });

/** Заполнение каждой из пяти звёзд от 0 до 100 %, чтобы 4,7 выглядело как 4,7, а не как 5. */
const fills = computed(() => [0, 1, 2, 3, 4].map((i) => Math.round(Math.min(Math.max((props.value ?? 0) - i, 0), 1) * 100)));
</script>

<template>
    <span class="inline-flex" :class="size === 'lg' ? 'gap-1 text-2xl' : 'gap-0.5 text-base'" :aria-label="`Оценка ${value ?? 0} из 5`" role="img">
        <span v-for="(fill, i) in fills" :key="i" class="relative leading-none text-slate-300" aria-hidden="true">
            ★
            <span class="absolute inset-0 overflow-hidden text-amber-400" :style="{ width: `${fill}%` }">★</span>
        </span>
    </span>
</template>
