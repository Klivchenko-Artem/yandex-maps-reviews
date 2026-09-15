import axios, { AxiosError } from 'axios';

/**
 * Один axios на всё приложение. Sanctum в SPA-режиме: сессионная кука плюс XSRF-TOKEN,
 * который axios сам перекладывает в заголовок X-XSRF-TOKEN.
 */
export const http = axios.create({
    baseURL: '/api',
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    timeout: 30_000,
});

let onUnauthorized: (() => void) | null = null;

/** Роутер подписывается, чтобы увести на вход, когда сессия истекла. */
export function setUnauthorizedHandler(handler: () => void): void {
    onUnauthorized = handler;
}

http.interceptors.response.use(
    (response) => response,
    (error: AxiosError) => {
        if (error.response?.status === 401 && !error.config?.url?.endsWith('/user')) {
            onUnauthorized?.();
        }
        return Promise.reject(error);
    },
);

export async function ensureCsrfCookie(): Promise<void> {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
}

export interface ApiErrorInfo {
    message: string;
    fields: Record<string, string>;
    status: number | null;
}

/** Приводит любую ошибку запроса к тому, что можно показать человеку. */
export function describeError(error: unknown): ApiErrorInfo {
    if (!axios.isAxiosError(error)) {
        return { message: 'Что-то пошло не так', fields: {}, status: null };
    }

    if (!error.response) {
        return {
            message: error.code === 'ECONNABORTED' ? 'Сервер не ответил вовремя' : 'Нет связи с сервером',
            fields: {},
            status: null,
        };
    }

    const { status, data } = error.response as { status: number; data: { message?: string; code?: string; errors?: Record<string, string[]> } };
    const fields = Object.fromEntries(Object.entries(data?.errors ?? {}).map(([key, messages]) => [key, messages[0]]));

    const fallback: Record<number, string> = {
        404: 'Не найдено',
        419: 'Сессия устарела, обновите страницу',
        429: 'Слишком много запросов, подождите минуту',
        503: 'Источник временно недоступен',
    };

    // Текст сервера показываем только там, где он адресован человеку: ошибки полей, отказ
    // источника с кодом и просроченная сессия (там объясняют, какие настройки проверить).
    // Для остального (500 «Server Error», прокси, чужой nginx) показываем свои тексты.
    const serverTextIsForHumans = status === 422 || status === 419 || (status === 503 && typeof data?.code === 'string');
    const message = Object.values(fields)[0] ?? (serverTextIsForHumans ? data?.message : undefined);

    return {
        message: message ?? fallback[status] ?? (status >= 500 ? 'Ошибка на сервере, попробуйте позже' : `Ошибка запроса (${status})`),
        fields,
        status,
    };
}
