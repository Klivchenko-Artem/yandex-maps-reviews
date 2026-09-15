<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakesYandexMaps;
use Tests\TestCase;

class YandexCheckCommandTest extends TestCase
{
    use FakesYandexMaps;

    private const URL = 'https://yandex.ru/maps/org/test/1000000001/';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['yandex.page_retry_delay_ms' => 0]);
    }

    /** @return list<array<string, mixed>> */
    private function manyReviews(): array
    {
        return array_map(fn (int $n) => $this->rawReview($n), range(1, 700));
    }

    public function test_passes_when_format_and_page_limit_are_as_expected(): void
    {
        // Настоящий Яндекс: 12 страниц, на 13-й ошибка 500.
        $this->fakeYandex($this->manyReviews(), [
            'reviewsResponse' => fn (int $page) => $page > 12 ? Http::response(['error' => ['code' => 500, 'message' => 'Internal error']]) : null,
        ]);

        $this->artisan('yandex:check', ['url' => self::URL])
            ->expectsOutputToContain('Потолок выдачи прежний: 12 страниц')
            ->assertSuccessful();
    }

    public function test_lowered_page_limit_is_reported_as_source_change(): void
    {
        Log::spy();
        $this->fakeYandex($this->manyReviews(), [
            'reviewsResponse' => fn (int $page) => $page > 10 ? Http::response(['error' => ['code' => 500, 'message' => 'Internal error']]) : null,
        ]);

        $this->artisan('yandex:check', ['url' => self::URL])
            ->expectsOutputToContain('опустил потолок')
            ->assertFailed();

        Log::shouldHaveReceived('log')->withArgs(fn ($level) => $level === 'critical')->once();
    }

    public function test_raised_page_limit_is_reported(): void
    {
        // Яндекс начал отдавать и 13-ю страницу: без сигнала мы бы молча теряли отзывы.
        $this->fakeYandex($this->manyReviews(), [
            'reviewsResponse' => fn (int $page) => $page === 13
                ? Http::response(['data' => ['reviews' => [$this->rawReview(601)], 'params' => ['count' => 700, 'totalPages' => 14]]])
                : null,
        ]);

        $this->artisan('yandex:check', ['url' => self::URL])
            ->expectsOutputToContain('YANDEX_MAX_PAGES')
            ->assertFailed();
    }

    public function test_changed_format_fails_with_critical_log(): void
    {
        Log::spy();
        $this->fakeYandex($this->manyReviews(), [
            'reviewsResponse' => fn () => Http::response('Bad Request', 400),
        ]);

        $this->artisan('yandex:check', ['url' => self::URL])->assertFailed();

        Log::shouldHaveReceived('log')->withArgs(fn ($level) => $level === 'critical')->once();
    }
}
