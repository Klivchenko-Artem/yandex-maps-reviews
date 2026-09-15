<?php

namespace App\Services\YandexMaps;

use App\Services\YandexMaps\Exceptions\SourceBlocked;
use App\Services\YandexMaps\Exceptions\SourceUnavailable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Общий на все воркеры «светофор» перед Яндексом.
 *
 * 1. Интервал: запросы идут не чаще min_interval_ms + случайная добавка,
 *    сколько бы воркеров ни работало. Время следующего разрешённого запроса
 *    лежит в кэше, запись под блокировкой.
 * 2. Автомат-предохранитель: после капчи или 429 запросы отказываются сразу,
 *    пока не пройдёт block_cooldown. Иначе 50 задач по очереди дожгут выход
 *    окончательно. Пауза своя у каждого выхода в интернет: забанили один прокси,
 *    остальные продолжают работать.
 */
class RequestThrottle
{
    /** Выход в интернет без прокси. */
    public const DIRECT = 'direct';

    private const NEXT_AT_KEY = 'yandex:throttle:next_at';

    private const BLOCKED_UNTIL_KEY = 'yandex:throttle:blocked_until:';

    public function __construct(
        private readonly Cache $cache,
        private readonly int $minIntervalMs,
        private readonly int $jitterMs,
        private readonly int $blockCooldown,
    ) {}

    public function beforeRequest(string $scope = self::DIRECT): void
    {
        $this->assertNotBlocked($scope);

        if ($this->minIntervalMs <= 0 && $this->jitterMs <= 0) {
            return;
        }

        // Под блокировкой только бронируем слот, это миллисекунды. Спим уже без неё:
        // иначе воркеры выстраиваются в очередь за замком и ловят таймаут ожидания, а не своего слота.
        try {
            $slot = $this->cache->lock('yandex:throttle:lock', 10)->block(10, function () {
                $slot = max(microtime(true), (float) $this->cache->get(self::NEXT_AT_KEY, 0));
                $delayMs = $this->minIntervalMs + ($this->jitterMs > 0 ? random_int(0, $this->jitterMs) : 0);
                $this->cache->put(self::NEXT_AT_KEY, $slot + $delayMs / 1000, 600);

                return $slot;
            });
        } catch (LockTimeoutException $e) {
            throw new SourceUnavailable('Не дождались очереди запросов к Яндексу', previous: $e);
        }

        $wait = $slot - microtime(true);
        if ($wait > 0) {
            Sleep::usleep((int) ($wait * 1_000_000));
        }
    }

    public function reportBlocked(string $reason, string $scope = self::DIRECT): void
    {
        $until = time() + $this->blockCooldown;
        $this->cache->put(self::BLOCKED_UNTIL_KEY.$scope, $until, $this->blockCooldown);

        Log::warning('Яндекс заблокировал запросы, ставим источник на паузу', [
            'reason' => $reason,
            'scope' => $scope,
            'blocked_until' => date(DATE_ATOM, $until),
        ]);
    }

    /** Сколько секунд осталось до снятия паузы (0, если не заблокированы). */
    public function secondsUntilUnblocked(string $scope = self::DIRECT): int
    {
        return max(0, (int) $this->cache->get(self::BLOCKED_UNTIL_KEY.$scope, 0) - time());
    }

    private function assertNotBlocked(string $scope): void
    {
        $left = $this->secondsUntilUnblocked($scope);
        if ($left > 0) {
            throw new SourceBlocked("Запросы к Яндексу на паузе после блокировки, осталось {$left} с", $left);
        }
    }
}
