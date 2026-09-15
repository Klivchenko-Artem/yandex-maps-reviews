<script setup lang="ts">
import type { Review } from '@/api/types';
import { formatDate } from '@/utils/format';
import { computed, ref } from 'vue';
import StarRating from './StarRating.vue';

const props = defineProps<{ review: Review }>();

const LONG_TEXT = 600;
const expanded = ref(false);
const isLong = computed(() => props.review.text.length > LONG_TEXT);
const shownText = computed(() =>
    isLong.value && !expanded.value ? `${props.review.text.slice(0, LONG_TEXT).trimEnd()}…` : props.review.text,
);
const initial = computed(() => props.review.author.name.trim().charAt(0).toUpperCase() || '?');
</script>

<template>
    <article class="rounded-xl border border-slate-200 bg-white p-4">
        <header class="flex items-start gap-3">
            <img
                v-if="review.author.avatar_url"
                :src="review.author.avatar_url"
                :alt="review.author.name"
                class="h-10 w-10 shrink-0 rounded-full bg-slate-200 object-cover"
                loading="lazy"
                referrerpolicy="no-referrer"
            />
            <div v-else class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-slate-200 font-medium text-slate-600">
                {{ initial }}
            </div>
            <div class="min-w-0 flex-1">
                <p class="font-medium break-words">{{ review.author.name }}</p>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-slate-500">
                    <StarRating :value="review.rating" />
                    <time :datetime="review.published_at">{{ formatDate(review.published_at) }}</time>
                </div>
            </div>
        </header>

        <p v-if="review.text" class="mt-3 break-words whitespace-pre-line">{{ shownText }}</p>
        <p v-else class="mt-3 text-sm text-slate-400 italic">Оценка без текста</p>
        <button v-if="isLong" type="button" class="mt-1 text-sm text-blue-600 hover:underline" @click="expanded = !expanded">
            {{ expanded ? 'Свернуть' : 'Читать полностью' }}
        </button>

        <div v-if="review.business_reply" class="mt-3 rounded-lg bg-slate-50 p-3 text-sm">
            <p class="mb-1 font-medium text-slate-700">Ответ организации</p>
            <p class="break-words whitespace-pre-line text-slate-600">{{ review.business_reply }}</p>
        </div>
    </article>
</template>
