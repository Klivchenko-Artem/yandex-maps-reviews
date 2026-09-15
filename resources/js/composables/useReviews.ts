import { organizationsApi } from '@/api';
import { describeError } from '@/api/http';
import type { PaginationMeta, Review } from '@/api/types';
import axios from 'axios';
import { onScopeDispose, ref, watch, type Ref } from 'vue';

/**
 * Одна страница отзывов (50 штук) с бэка. При быстром листании предыдущий запрос
 * отменяется, чтобы медленный ответ со второй страницы не перетёр уже открытую пятую.
 */
export function useReviews(organizationId: Ref<number>, page: Ref<number>) {
    const reviews = ref<Review[]>([]);
    const meta = ref<PaginationMeta | null>(null);
    const loading = ref(false);
    const error = ref<string | null>(null);
    let controller: AbortController | null = null;

    /** silent: фоновое обновление во время парсинга, данные меняются без мигания «загрузки». */
    async function load(silent = false): Promise<void> {
        controller?.abort();
        const current = new AbortController();
        controller = current;
        if (!silent) {
            loading.value = true;
            error.value = null;
        }

        try {
            const response = await organizationsApi.reviews(organizationId.value, page.value, current.signal);
            reviews.value = response.data;
            meta.value = response.meta;
        } catch (e) {
            // Сбой фонового обновления не прячет уже показанные отзывы: следующий опрос попробует снова.
            if (axios.isCancel(e) || silent) {
                return;
            }
            error.value = describeError(e).message;
        } finally {
            if (controller === current) {
                loading.value = false;
                controller = null;
            }
        }
    }

    watch([organizationId, page], () => load(), { immediate: true });
    onScopeDispose(() => controller?.abort());

    return { reviews, meta, loading, error, reload: load };
}
