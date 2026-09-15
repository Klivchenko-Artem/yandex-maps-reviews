<?php

use App\Enums\SyncStatus;
use App\Models\Organization;
use App\Services\Sync\SyncDispatcher;
use App\Services\YandexMaps\Exceptions\SourceException;
use App\Services\YandexMaps\Exceptions\SourceUnavailable;
use App\Services\YandexMaps\OrganizationLinkResolver;
use App\Services\YandexMaps\YandexMapsClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

/*
 * Канарейка: парсит эталонную карточку, ничего не сохраняя, и падает с ненулевым кодом,
 * если парсер перестал понимать Яндекс. Висит в планировщике, так что поломка видна в логе
 * раньше, чем пользователи увидят пустые карточки.
 *
 * Кроме формата проверяет границы выдачи. Конец списка Яндекс отдаёт той же ошибкой 500,
 * что и разовый сбой, поэтому при обычном парсинге смену потолка не отличить от сбоя.
 * Здесь проверяем явно: последняя страница по нашему потолку должна отдаваться, а следующая нет.
 *
 * Уровень critical означает «парсер сломан для всех», и именно на него вешается алерт,
 * поэтому капча, таймаут и отказ сети им не считаются: ждать надо, а не чинить код.
 */
Artisan::command('yandex:check {url? : ссылка на эталонную карточку}', function (OrganizationLinkResolver $resolver, YandexMapsClient $client) {
    $url = $this->argument('url') ?? config('yandex.canary_url');
    $maxPages = (int) config('yandex.max_pages');
    $pageSize = (int) config('yandex.page_size');

    $fail = function (string $level, string $code, string $message, array $context = []) {
        Log::log($level, 'Канарейка Яндекс.Карт упала', ['code' => $code, 'error' => $message] + $context);
        $this->error("[{$code}] {$message}");

        return 1;
    };

    $levelFor = fn (SourceException $e) => $e->errorCode() === 'source_changed' ? 'critical' : 'error';

    try {
        $page = $client->openOrganization($resolver->resolve($url));
        $first = $client->fetchReviewsPage($page, 1);
    } catch (SourceException $e) {
        return $fail($levelFor($e), $e->errorCode(), $e->getMessage(), ['details' => $e->context()]);
    }

    $org = $page->organization;
    $this->info("{$org->name}: рейтинг {$org->rating}, оценок {$org->ratingsCount}, отзывов {$org->reviewsCount}");
    $this->info('Первая страница: '.count($first->reviews)." отзывов, доступно страниц: {$first->availablePages}");

    if ($first->totalReviews <= $pageSize * $maxPages) {
        $this->comment('У эталона меньше отзывов, чем потолок выдачи: границы не проверить, возьмите карточку крупнее');

        return 0;
    }

    try {
        $last = $client->fetchReviewsPage($page, $maxPages);
    } catch (SourceUnavailable $e) {
        // Отказ пришёл телом ответа самого API: так Яндекс говорит «страниц больше нет».
        // Сеть и таймаут сюда не попадают, иначе разрыв связи выглядел бы как смена потолка.
        return $e->fromApiBody()
            ? $fail('critical', 'source_changed', "Страница {$maxPages} не отдаётся: похоже, Яндекс опустил потолок выдачи ({$e->getMessage()})")
            : $fail('error', $e->errorCode(), "Не удалось проверить потолок выдачи: {$e->getMessage()}");
    } catch (SourceException $e) {
        return $fail($levelFor($e), $e->errorCode(), $e->getMessage(), ['details' => $e->context()]);
    }

    if (count($last->reviews) === 0) {
        return $fail('critical', 'source_changed', "Страница {$maxPages} пустая при {$first->totalReviews} отзывах: потолок выдачи изменился");
    }

    try {
        $beyond = $client->probeReviewsPage($page, $maxPages + 1);
    } catch (SourceException $e) {
        return $fail('error', $e->errorCode(), "Не удалось проверить страницу за потолком: {$e->getMessage()}");
    }

    if ($beyond !== null && count($beyond->reviews) > 0) {
        return $fail('warning', 'limit_raised', 'Яндекс отдаёт больше '.$maxPages.' страниц: поднимите YANDEX_MAX_PAGES, иначе теряем часть отзывов');
    }

    $this->info("Потолок выдачи прежний: {$maxPages} страниц по {$pageSize}");

    return 0;
})->purpose('Проверить, что парсер Яндекс.Карт понимает текущий формат ответа и потолок выдачи');

Artisan::command('organizations:sync-all', function (SyncDispatcher $dispatcher) {
    $count = 0;
    Organization::query()->orderBy('id')->each(function (Organization $organization) use ($dispatcher, &$count) {
        $run = $dispatcher->dispatch($organization);
        $count += $run->status === SyncStatus::Queued ? 1 : 0;
    });

    $this->info("В очереди: {$count}");
})->purpose('Поставить в очередь парсинг всех подключённых организаций');

Artisan::command('organizations:close-stale', function (SyncDispatcher $dispatcher) {
    $closed = $dispatcher->closeStale();

    $this->info("Закрыто зависших запусков: {$closed}");
})->purpose('Закрыть загрузки, которые давно не подают признаков жизни');

// Не в 04:00 и не ровно в час: канарейка не должна стучаться к Яндексу одновременно с ночным обходом.
Schedule::command('yandex:check')->cron('25 */3 * * *')->withoutOverlapping(10);
Schedule::command('organizations:sync-all')->dailyAt('04:00')->withoutOverlapping(60);
// Организация с потерянной задачей иначе стоит с неактивной кнопкой «Обновить» до самого порога.
Schedule::command('organizations:close-stale')->everyFiveMinutes()->withoutOverlapping(10);
