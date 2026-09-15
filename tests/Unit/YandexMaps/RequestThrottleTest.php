<?php

namespace Tests\Unit\YandexMaps;

use App\Services\YandexMaps\Exceptions\SourceBlocked;
use App\Services\YandexMaps\RequestThrottle;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Tests\TestCase;

class RequestThrottleTest extends TestCase
{
    private function throttle(Repository $cache, int $intervalMs = 150): RequestThrottle
    {
        return new RequestThrottle($cache, minIntervalMs: $intervalMs, jitterMs: 0, blockCooldown: 60);
    }

    public function test_requests_are_spaced_by_interval_across_instances(): void
    {
        // Общий кэш, как у нескольких воркеров.
        $cache = new Repository(new ArrayStore);
        $a = $this->throttle($cache);
        $b = $this->throttle($cache);

        $start = microtime(true);
        $a->beforeRequest();
        $b->beforeRequest();
        $a->beforeRequest();

        $this->assertGreaterThanOrEqual(0.29, microtime(true) - $start);
    }

    public function test_slot_is_reserved_without_holding_the_lock_while_sleeping(): void
    {
        $cache = new Repository(new ArrayStore);
        $this->throttle($cache, 500)->beforeRequest();

        // Следующий слот уже забронирован на будущее, а замок свободен: другой воркер не ждёт его освобождения.
        $this->assertGreaterThan(microtime(true), (float) $cache->get('yandex:throttle:next_at'));
        $this->assertTrue($cache->lock('yandex:throttle:lock', 1)->get());
    }

    public function test_block_pauses_every_instance(): void
    {
        $cache = new Repository(new ArrayStore);
        $this->throttle($cache)->reportBlocked('капча');

        $this->expectException(SourceBlocked::class);

        $this->throttle($cache)->beforeRequest();
    }
}
