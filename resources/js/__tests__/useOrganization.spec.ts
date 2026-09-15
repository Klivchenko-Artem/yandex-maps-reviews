import type { Organization, SyncRun } from '@/api/types';
import { POLL_INTERVAL_MS, useOrganization } from '@/composables/useOrganization';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, ref } from 'vue';

const get = vi.fn();
vi.mock('@/api', () => ({ organizationsApi: { get: (...args: unknown[]) => get(...args), sync: vi.fn() } }));

function org(status: SyncRun['status'], fetched = 0): Organization {
    return {
        id: 1,
        source: 'yandex',
        external_id: '1',
        url: 'https://yandex.ru/maps/org/1/',
        name: 'Тест',
        address: null,
        rating: 4.5,
        ratings_count: 10,
        reviews_count: 5,
        last_synced_at: null,
        sync: { status, is_active: ['queued', 'running', 'retrying'].includes(status), reviews_fetched: fetched } as SyncRun,
    };
}

function axiosError(status: number) {
    // Сервер отдаёт текст фреймворка, пользователь его видеть не должен.
    return Object.assign(new Error(`HTTP ${status}`), {
        isAxiosError: true,
        response: { status, data: { message: 'No query results for model [App\Models\Organization] 1' } },
    });
}

describe('useOrganization', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        get.mockReset();
    });
    afterEach(() => vi.useRealTimers());

    it('опрашивает, пока парсинг идёт, и сообщает о завершении один раз', async () => {
        get.mockResolvedValueOnce(org('running')).mockResolvedValueOnce(org('running')).mockResolvedValue(org('completed'));
        const finished = vi.fn();
        const scope = effectScope();
        scope.run(() => useOrganization(ref(1), finished));

        await vi.advanceTimersByTimeAsync(0);
        await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS);
        await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS);
        await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS * 3);

        expect(get).toHaveBeenCalledTimes(3);
        expect(finished).toHaveBeenCalledTimes(1);
        scope.stop();
    });

    it('сообщает о каждой новой порции отзывов, пока парсинг идёт', async () => {
        get.mockResolvedValueOnce(org('queued', 0))
            .mockResolvedValueOnce(org('running', 50))
            .mockResolvedValueOnce(org('running', 50))
            .mockResolvedValueOnce(org('running', 100))
            .mockResolvedValue(org('completed', 100));
        const progress = vi.fn();
        const finished = vi.fn();
        const scope = effectScope();
        scope.run(() => useOrganization(ref(1), finished, progress));

        await vi.advanceTimersByTimeAsync(0);
        for (let i = 0; i < 5; i++) {
            await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS);
        }

        // На 0 ещё нечего показывать, повтор 50 не должен лишний раз перезагружать список.
        expect(progress.mock.calls.map(([run]) => run.reviews_fetched)).toEqual([50, 100]);
        expect(finished).toHaveBeenCalledTimes(1);
        scope.stop();
    });

    it('не заводит опрос заново, если ответ пришёл после ухода со страницы', async () => {
        let resolve!: (value: Organization) => void;
        get.mockImplementationOnce(() => new Promise((r) => (resolve = r))).mockResolvedValue(org('running'));
        const scope = effectScope();
        scope.run(() => useOrganization(ref(1), vi.fn()));

        scope.stop();
        resolve(org('running'));
        await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS * 5);

        expect(get).toHaveBeenCalledTimes(1);
    });

    it('прекращает опрос, если организацию удалили', async () => {
        get.mockResolvedValueOnce(org('running')).mockRejectedValue(axiosError(404));
        const scope = effectScope();
        const state = scope.run(() => useOrganization(ref(1), vi.fn()))!;

        await vi.advanceTimersByTimeAsync(0);
        await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS * 5);

        expect(get).toHaveBeenCalledTimes(2);
        expect(state.error.value).toBe('Не найдено');
        scope.stop();
    });
});
