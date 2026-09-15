<?php

return [
    'base_url' => env('YANDEX_BASE_URL', 'https://yandex.ru'),

    // Эталонная карточка для `php artisan yandex:check`: крупная, с тысячами отзывов, вряд ли исчезнет.
    'canary_url' => env('YANDEX_CANARY_URL', 'https://yandex.ru/maps/org/yandeks/1124715036/'),

    // Таймаут одного запроса, секунды.
    'timeout' => (int) env('YANDEX_TIMEOUT', 20),

    // Больше 12 страниц по 50 Яндекс не отдаёт: на 13-й fetchReviews отвечает ошибкой 500.
    'page_size' => 50,
    'max_pages' => (int) env('YANDEX_MAX_PAGES', 12),

    // Минимальный интервал между любыми запросами к Яндексу со всех воркеров сразу
    // плюс случайная добавка, чтобы не стучать с ровным, «машинным» шагом.
    'min_interval_ms' => (int) env('YANDEX_MIN_INTERVAL_MS', 1500),
    'jitter_ms' => (int) env('YANDEX_JITTER_MS', 1000),

    // Сколько раз перезапросить страницу отзывов на месте при разовой 5xx и с какой паузой (растёт с каждой попыткой).
    'page_retries' => (int) env('YANDEX_PAGE_RETRIES', 2),
    'page_retry_delay_ms' => (int) env('YANDEX_PAGE_RETRY_DELAY_MS', 3000),

    // Сколько секунд не ходить к Яндексу после капчи или 429.
    'block_cooldown' => (int) env('YANDEX_BLOCK_COOLDOWN', 900),

    // Прокси через запятую (http://user:pass@host:port). Если пусто, ходим напрямую.
    'proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('YANDEX_PROXIES', ''))))),

    'user_agents' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36',
    ],
];
