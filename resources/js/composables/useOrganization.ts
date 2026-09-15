import { organizationsApi } from '@/api';
import { describeError } from '@/api/http';
import type { Organization, SyncRun } from '@/api/types';
import { onScopeDispose, ref, watch, type Ref } from 'vue';

export const POLL_INTERVAL_MS = 2000;

/**
 * Карточка организации с опросом прогресса: пока парсинг идёт, раз в 2 секунды
 * перечитывает состояние. Зовёт onSyncProgress, когда скачалась новая порция отзывов,
 * и onSyncFinished, когда запуск завершился.
 *
 * Опрос, а не WebSocket: прогресс меняется раз в пару секунд, а лишний сервер
 * (Reverb, Pusher) ради этого перебор. В README это вынесено в «что дальше».
 */
export function useOrganization(id: Ref<number>, onSyncFinished: () => void, onSyncProgress?: (run: SyncRun) => void) {
    const organization = ref<Organization | null>(null);
    const loading = ref(false);
    const error = ref<string | null>(null);
    let timer: ReturnType<typeof setTimeout> | null = null;
    let wasActive = false;
    let lastFetched = -1;
    let requestSeq = 0;
    // Ответ, пришедший после ухода со страницы, не должен заводить таймер заново.
    let disposed = false;

    async function load(silent = false): Promise<void> {
        const seq = ++requestSeq;
        if (!silent) {
            loading.value = true;
        }

        let keepPolling = true;
        try {
            const fresh = await organizationsApi.get(id.value);
            if (seq !== requestSeq) {
                return;
            }
            organization.value = fresh;
            error.value = null;
        } catch (e) {
            if (seq !== requestSeq) {
                return;
            }
            const info = describeError(e);
            error.value = info.message;
            // Организацию удалили или сессия кончилась, опрашивать больше нечего.
            // На сетевой сбой опрос продолжаем: связь могла мигнуть.
            keepPolling = info.status !== 404 && info.status !== 401;
        } finally {
            if (seq === requestSeq) {
                loading.value = false;
                if (keepPolling) {
                    schedule();
                } else {
                    stop();
                }
            }
        }
    }

    function schedule(): void {
        stop();
        if (disposed) {
            return;
        }

        const run = organization.value?.sync ?? null;
        const active = run?.is_active ?? false;
        if (wasActive && !active) {
            onSyncFinished();
        }
        wasActive = active;

        // Бэк пишет отзывы в базу постранично, по ходу обхода. Сообщаем о каждой новой порции,
        // чтобы первые 50 отзывов появились через пару секунд, а не после всех 12 страниц.
        if (active && run && run.reviews_fetched !== lastFetched) {
            if (run.reviews_fetched > 0) {
                onSyncProgress?.(run);
            }
            lastFetched = run.reviews_fetched;
        }

        if (active) {
            timer = setTimeout(() => load(true), POLL_INTERVAL_MS);
        }
    }

    function stop(): void {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    }

    async function resync(): Promise<void> {
        if (!organization.value) {
            return;
        }
        try {
            const run = await organizationsApi.sync(organization.value.id);
            organization.value = { ...organization.value, sync: run };
            error.value = null;
            schedule();
        } catch (e) {
            error.value = describeError(e).message;
        }
    }

    watch(
        id,
        () => {
            organization.value = null;
            wasActive = false;
            lastFetched = -1;
            load();
        },
        { immediate: true },
    );

    onScopeDispose(() => {
        disposed = true;
        requestSeq++;
        stop();
    });

    return { organization, loading, error, reload: load, resync };
}
