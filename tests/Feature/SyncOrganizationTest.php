<?php

namespace Tests\Feature;

use App\Enums\SyncStatus;
use App\Jobs\SyncOrganization;
use App\Models\Organization;
use App\Models\Review;
use App\Models\ReviewRevision;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Sync\OrganizationSyncService;
use App\Services\YandexMaps\RequestThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakesYandexMaps;
use Tests\TestCase;

class SyncOrganizationTest extends TestCase
{
    use FakesYandexMaps;
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $this->organization = Organization::factory()->for(User::factory())->create([
            'external_id' => self::ORG_ID,
            'name' => null,
            'rating' => null,
            'ratings_count' => 0,
            'reviews_count' => 0,
            'last_synced_at' => null,
        ]);
    }

    private function runSync(?SyncRun $run = null): SyncRun
    {
        $run ??= $this->organization->syncRuns()->create(['status' => SyncStatus::Queued]);

        $job = (new SyncOrganization($run))->withFakeQueueInteractions();
        $job->handle(app(OrganizationSyncService::class));
        $this->lastJob = $job;

        return $run->fresh();
    }

    private SyncOrganization $lastJob;

    /** @return list<array<string, mixed>> */
    private function reviews(int $count): array
    {
        return array_map(fn (int $n) => $this->rawReview($n), range(1, $count));
    }

    public function test_full_sync_walks_all_pages_and_stores_card(): void
    {
        $this->fakeYandex($this->reviews(120));

        $run = $this->runSync();

        $this->assertSame(SyncStatus::Completed, $run->status);
        $this->assertSame(3, $run->pages_total);
        $this->assertSame(3, $run->pages_done);
        $this->assertSame(120, $run->reviews_fetched);
        $this->assertSame(120, $run->reviews_created);
        $this->assertSame(100, $run->progressPercent());
        $this->lastJob->assertNotFailed();
        $this->lastJob->assertNotReleased();

        $organization = $this->organization->fresh();
        $this->assertSame('Кофейня «Тестовая»', $organization->name);
        $this->assertSame(4.7, $organization->rating);
        $this->assertSame(1234, $organization->ratings_count);
        $this->assertSame(120, $organization->reviews_count);
        $this->assertNotNull($organization->last_synced_at);

        $this->assertSame(120, Review::count());
        $this->assertSame(1, $organization->snapshots()->count());

        // Все запросы к API отзывов подписаны и несут токен со страницы.
        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'fetchReviews')
            || (str_contains($request->url(), 's=') && str_contains($request->url(), 'csrfToken=fixturecsrf')));
    }

    public function test_stops_at_yandex_page_limit(): void
    {
        // У организации 700 отзывов, но Яндекс отдаёт только 12 страниц по 50.
        $this->fakeYandex($this->reviews(700));

        $run = $this->runSync();

        $this->assertSame(SyncStatus::Completed, $run->status);
        $this->assertSame(12, $run->pages_total);
        $this->assertSame(600, Review::count());
        Http::assertSentCount(1 + 12);
    }

    public function test_repeated_sync_updates_instead_of_duplicating_and_logs_revisions(): void
    {
        $reviews = $this->reviews(60);
        $this->fakeYandex($reviews);
        $this->runSync();

        // Между парсингами: один отзыв отредактирован, на второй ответила организация,
        // третий удалён автором, появился новый, рейтинг поменялся.
        $reviews[0]['text'] = 'Исправленный текст';
        $reviews[0]['rating'] = 1;
        $reviews[1]['businessComment'] = ['text' => 'Спасибо!'];
        unset($reviews[2]);
        array_unshift($reviews, $this->rawReview(999, ['updatedTime' => '2026-09-10T00:00:00.000Z']));
        $this->fakeYandex(array_values($reviews), ['ratingData' => ['ratingCount' => 1240, 'ratingValue' => 4.6, 'reviewCount' => 60]]);

        $run = $this->runSync();

        $this->assertSame(SyncStatus::Completed, $run->status);
        $this->assertSame(1, $run->reviews_created);
        $this->assertSame(2, $run->reviews_updated);
        $this->assertSame(1, $run->reviews_removed);

        $this->assertSame(61, Review::count(), 'дублей нет, удалённый отзыв остался строкой');
        $this->assertSame(60, Review::visible()->count());
        $this->assertNotNull(Review::where('external_id', 'review3')->value('removed_at'));

        $changed = ReviewRevision::where('event', 'changed')->with('review')->get()->keyBy('review.external_id');
        $this->assertSame(['old' => 'Текст отзыва 1', 'new' => 'Исправленный текст'], $changed['review1']->changes['text']);
        $this->assertSame(['old' => 2, 'new' => 1], $changed['review1']->changes['rating']);
        $this->assertSame(['old' => null, 'new' => 'Спасибо!'], $changed['review2']->changes['business_reply']);
        $this->assertSame(1, ReviewRevision::where('event', 'removed')->count());

        $this->assertSame(2, $this->organization->snapshots()->count());
        $this->assertSame(1240, $this->organization->fresh()->ratings_count);

        $history = $this->actingAs($this->organization->user)
            ->getJson("/api/organizations/{$this->organization->id}/history")
            ->assertOk();
        $this->assertSame(['old' => 1234, 'new' => 1240], $history->json('data.snapshots.0.changes.ratings_count'));
        $this->assertSame(['old' => 4.7, 'new' => 4.6], $history->json('data.snapshots.0.changes.rating'));
        $this->assertTrue($history->json('data.snapshots.1.is_first'));
    }

    public function test_review_that_came_back_is_restored(): void
    {
        $reviews = $this->reviews(10);
        $this->fakeYandex($reviews);
        $this->runSync();
        Review::where('external_id', 'review5')->update(['removed_at' => now()->subDay()]);

        $run = $this->runSync();

        $this->assertSame(0, $run->reviews_removed);
        $this->assertNull(Review::where('external_id', 'review5')->value('removed_at'));
        $this->assertSame(1, ReviewRevision::where('event', 'restored')->count());
    }

    public function test_reviews_pushed_out_of_600_window_are_not_marked_removed(): void
    {
        // Раньше в базе был старый отзыв, теперь он за пределами последних 600, и это не удаление.
        Review::factory()->for($this->organization)->create([
            'external_id' => 'very-old',
            'published_at' => '2020-01-01 00:00:00',
        ]);
        $this->fakeYandex($this->reviews(700));

        $run = $this->runSync();

        $this->assertSame(0, $run->reviews_removed);
        $this->assertNull(Review::where('external_id', 'very-old')->value('removed_at'));
    }

    public function test_changed_response_format_fails_loudly_without_retry(): void
    {
        Log::spy();
        $this->fakeYandex($this->reviews(10), [
            'reviewsResponse' => fn () => Http::response(['data' => ['items' => [], 'meta' => []]]),
        ]);

        $run = $this->runSync();

        $this->assertSame(SyncStatus::SourceChanged, $run->status);
        $this->assertSame('source_changed', $run->error_code);
        $this->assertStringContainsString('Яндекс изменил формат', $run->error_message);
        $this->lastJob->assertFailed();
        $this->lastJob->assertNotReleased();
        Log::shouldHaveReceived('critical')->once();
        $this->assertSame(0, Review::count());
    }

    public function test_suspiciously_few_reviews_are_treated_as_source_change(): void
    {
        // Каждая страница отвечает одним и тем же, например, сломалась пагинация.
        $reviews = $this->reviews(150);
        $this->fakeYandex($reviews, [
            'reviewsResponse' => fn (int $page) => Http::response(['data' => [
                'reviews' => array_slice($reviews, 0, 50),
                'params' => ['count' => 150, 'totalPages' => 3],
            ]]),
        ]);

        $run = $this->runSync();

        $this->assertSame(SyncStatus::SourceChanged, $run->status);
        $this->assertStringContainsString('получено 50 отзывов из ожидаемых 150', $run->error_message);
    }

    public function test_captcha_pauses_source_and_releases_job_for_later(): void
    {
        $this->fakeYandex($this->reviews(10), [
            'reviewsResponse' => fn () => Http::response(['type' => 'captcha', 'captcha' => ['captcha-page' => 'https://yandex.ru/showcaptcha?x']]),
        ]);

        $run = $this->runSync();

        $this->assertSame(SyncStatus::Retrying, $run->status);
        $this->assertSame('blocked', $run->error_code);
        $this->lastJob->assertNotFailed();
        $this->lastJob->assertReleased();

        $throttle = app(RequestThrottle::class);
        $this->assertGreaterThan(0, $throttle->secondsUntilUnblocked());

        // Пока действует пауза, следующая задача даже не ходит в Яндекс.
        Http::fake(fn () => throw new \LogicException('запрос во время паузы'));
        $second = Organization::factory()->create();
        $secondRun = $second->syncRuns()->create(['status' => SyncStatus::Queued]);
        $job = (new SyncOrganization($secondRun))->withFakeQueueInteractions();
        $job->handle(app(OrganizationSyncService::class));

        $this->assertSame(SyncStatus::Retrying, $secondRun->fresh()->status);
        $job->assertReleased();
    }

    public function test_network_failure_is_retried_with_backoff(): void
    {
        Http::fake(['*' => Http::response('Bad gateway', 502)]);

        $run = $this->runSync();

        $this->assertSame(SyncStatus::Retrying, $run->status);
        $this->assertSame('unavailable', $run->error_code);
        $this->lastJob->assertReleased(SyncOrganization::BACKOFF[0]);
    }

    public function test_missing_organization_fails_without_retry(): void
    {
        Http::fake(['*' => Http::response(preg_replace('/"items": \[.*\]\}, "id"/s', '"items": []}, "id"', $this->orgPageHtml()))]);

        $run = $this->runSync();

        $this->assertSame(SyncStatus::Failed, $run->status);
        $this->assertSame('not_found', $run->error_code);
        $this->lastJob->assertFailed();
    }

    public function test_single_page_error_is_retried_in_place_without_restarting_walk(): void
    {
        $failures = 1;
        $this->fakeYandex($this->reviews(120), [
            'reviewsResponse' => function (int $page) use (&$failures) {
                if ($page === 2 && $failures-- > 0) {
                    return Http::response(['error' => ['code' => 500, 'message' => 'Internal error in /business/fetchReviews']]);
                }

                return null;
            },
        ]);

        $run = $this->runSync();

        $this->assertSame(SyncStatus::Completed, $run->status);
        $this->lastJob->assertNotReleased();
        // Карточка + 3 страницы + один повтор второй, без повторного обхода первой.
        Http::assertSentCount(5);
    }

    public function test_run_totals_survive_attempt_that_failed_midway(): void
    {
        $failures = 3;
        $this->fakeYandex($this->reviews(120), [
            'reviewsResponse' => function (int $page) use (&$failures) {
                // Третья страница падает на всех повторах на месте, и вся попытка уходит на повтор.
                if ($page === 3 && $failures-- > 0) {
                    return Http::response('Bad gateway', 502);
                }

                return null;
            },
        ]);

        $run = $this->runSync();
        $this->assertSame(SyncStatus::Retrying, $run->status);
        $this->lastJob->assertReleased();
        $this->assertSame(100, Review::count(), 'первая попытка успела записать две страницы');

        $run = $this->runSync($run);

        $this->assertSame(SyncStatus::Completed, $run->status);
        $this->assertSame(2, $run->attempts);
        $this->assertSame(120, $run->reviews_created, 'новыми считаются и те, что записала упавшая попытка');
        $this->assertSame(120, Review::count());
    }

    public function test_rejected_signature_is_source_change(): void
    {
        $this->fakeYandex($this->reviews(10), [
            'reviewsResponse' => fn () => Http::response('Bad Request', 400),
        ]);

        $run = $this->runSync();

        $this->assertSame(SyncStatus::SourceChanged, $run->status);
        $this->assertStringContainsString('подписи', $run->error_message);
        $this->lastJob->assertFailed();
    }

    public function test_review_missing_inside_600_window_is_marked_removed(): void
    {
        // Отзыв новее самого старого из полученных, но в выдаче его нет. Значит, действительно удалён.
        Review::factory()->for($this->organization)->create([
            'external_id' => 'deleted-recently',
            'published_at' => '2026-08-31 12:00:00',
        ]);
        $this->fakeYandex($this->reviews(700));

        $run = $this->runSync();

        $this->assertSame(1, $run->reviews_removed);
        $this->assertNotNull(Review::where('external_id', 'deleted-recently')->value('removed_at'));
    }

    public function test_count_changed_during_walk_skips_removal_marking(): void
    {
        Review::factory()->for($this->organization)->create(['external_id' => 'maybe-shifted', 'published_at' => '2026-08-31 12:00:00']);
        $reviews = $this->reviews(150);
        $this->fakeYandex($reviews, [
            // Пока ходили по страницам, один отзыв удалили: на последней странице счётчик уже 149.
            'reviewsResponse' => fn (int $page) => $page === 3
                ? Http::response(['data' => ['reviews' => array_slice($reviews, 100, 49), 'params' => ['count' => 149, 'totalPages' => 3]]])
                : null,
        ]);

        $run = $this->runSync();

        $this->assertSame(SyncStatus::Completed, $run->status);
        $this->assertSame(0, $run->reviews_removed);
        $this->assertNull(Review::where('external_id', 'maybe-shifted')->value('removed_at'));
    }

    public function test_merged_card_moves_organization_to_new_id_without_marking_removed(): void
    {
        $this->organization->update(['external_id' => '555555555']);
        Review::factory()->for($this->organization)->create(['external_id' => 'old-review', 'published_at' => '2026-08-31 12:00:00']);
        $this->fakeYandex($this->reviews(10), ['redirectOrgTo' => self::ORG_ID]);

        $run = $this->runSync();

        $this->assertSame(SyncStatus::Completed, $run->status);
        // На новый id переезжаем, иначе ссылка с ним заведёт вторую организацию с копией отзывов.
        $this->assertSame(self::ORG_ID, $this->organization->fresh()->external_id);
        // А удалённые в этот раз не трогаем: отзывы могли приехать из второй карточки.
        $this->assertNull(Review::where('external_id', 'old-review')->value('removed_at'));
        $this->assertSame(0, $run->reviews_removed);
    }

    public function test_merged_card_keeps_old_id_when_new_one_is_already_connected(): void
    {
        $this->organization->update(['external_id' => '555555555']);
        $this->organization->user->organizations()->create(['external_id' => self::ORG_ID, 'url' => 'https://yandex.ru/maps/org/'.self::ORG_ID.'/']);
        $this->fakeYandex($this->reviews(10), ['redirectOrgTo' => self::ORG_ID]);

        $this->runSync();

        $this->assertSame('555555555', $this->organization->fresh()->external_id);
    }

    public function test_missing_rating_block_with_reviews_is_a_source_change(): void
    {
        $this->organization->update(['rating' => 4.9, 'ratings_count' => 21229, 'reviews_count' => 5864]);
        // ratingData пропал, а отзывы на месте: это переименованное поле, а не «ноль оценок».
        $this->fakeYandex($this->reviews(10), ['ratingData' => null]);

        $run = $this->runSync();

        $this->assertSame(SyncStatus::SourceChanged, $run->status);
        $this->assertStringContainsString('ratingData', $run->error_message);

        // Главное: верные цифры не переписаны нулями.
        $organization = $this->organization->fresh();
        $this->assertSame(4.9, $organization->rating);
        $this->assertSame(21229, $organization->ratings_count);
        $this->assertSame(5864, $organization->reviews_count);
    }

    public function test_card_is_saved_before_the_walk_finishes(): void
    {
        // Обход срывается на второй странице, но название и цифры карточки уже разобраны.
        $this->fakeYandex($this->reviews(120), [
            'reviewsResponse' => fn (int $page) => $page === 2 ? Http::response('Bad gateway', 502) : null,
        ]);

        $run = $this->runSync();

        $this->assertSame(SyncStatus::Retrying, $run->status);

        $organization = $this->organization->fresh();
        $this->assertSame('Кофейня «Тестовая»', $organization->name);
        $this->assertSame(120, $organization->reviews_count);
        // А вот «успешно обновлено» не ставим: обход не доехал.
        $this->assertNull($organization->last_synced_at);
    }

    public function test_mass_loss_of_business_replies_rolls_back_the_page(): void
    {
        foreach (range(1, 12) as $n) {
            Review::factory()->for($this->organization)->create([
                'external_id' => "review{$n}",
                'business_reply' => 'Спасибо за отзыв!',
            ]);
        }
        // Яндекс переименовал businessComment: ответы пропали у всех разом.
        $this->fakeYandex($this->reviews(12));

        $run = $this->runSync();

        $this->assertSame(SyncStatus::SourceChanged, $run->status);
        $this->assertStringContainsString('ответы организации', $run->error_message);
        $this->assertSame('Спасибо за отзыв!', Review::where('external_id', 'review1')->value('business_reply'));
        $this->assertSame(0, ReviewRevision::count(), 'ложных «ответ убран» в истории не появилось');
    }

    public function test_lowered_page_limit_is_a_source_change_not_five_retries(): void
    {
        // Яндекс обещал 12 страниц, а на пятой отвечает своей ошибкой: потолок опустили.
        $this->fakeYandex($this->reviews(600), [
            'reviewsResponse' => fn (int $page) => $page === 5
                ? Http::response(['error' => ['code' => 500, 'message' => 'Internal error']])
                : null,
        ]);

        $run = $this->runSync();

        $this->assertSame(SyncStatus::SourceChanged, $run->status);
        $this->assertStringContainsString('потолок выдачи', $run->error_message);
        $this->lastJob->assertNotReleased();
    }

    public function test_waiting_out_a_block_does_not_spend_retry_budget(): void
    {
        $this->fakeYandex($this->reviews(10), [
            'reviewsResponse' => fn () => Http::response(['type' => 'captcha', 'captcha' => ['captcha-page' => 'https://yandex.ru/showcaptcha?x']]),
        ]);

        $run = $this->runSync();

        $this->assertSame(SyncStatus::Retrying, $run->status);
        $this->assertSame(1, $run->blocked_waits);
        $this->lastJob->assertReleased();
        $this->lastJob->assertNotFailed();
    }

    public function test_endless_block_eventually_gives_up(): void
    {
        $this->fakeYandex($this->reviews(10), [
            'reviewsResponse' => fn () => Http::response(['type' => 'captcha', 'captcha' => ['captcha-page' => 'https://yandex.ru/showcaptcha?x']]),
        ]);

        $run = $this->organization->syncRuns()->create(['status' => SyncStatus::Queued]);
        $run->update(['blocked_waits' => SyncOrganization::MAX_BLOCKED_WAITS - 1]);

        $run = $this->runSync($run);

        $this->assertSame(SyncStatus::Failed, $run->status);
        $this->assertSame('blocked', $run->error_code);
        $this->lastJob->assertFailed();
    }

    public function test_duplicate_review_on_a_page_does_not_break_the_transaction(): void
    {
        $reviews = $this->reviews(3);
        $this->fakeYandex($reviews, [
            'reviewsResponse' => fn () => Http::response(['data' => [
                // Один и тот же отзыв дважды: без дедупликации вторая вставка валит unique.
                'reviews' => [$reviews[0], $reviews[0], $reviews[1]],
                'params' => ['count' => 2, 'totalPages' => 1],
            ]]),
        ]);

        $run = $this->runSync();

        $this->assertSame(SyncStatus::Completed, $run->status);
        $this->assertSame(2, Review::count());
    }

    public function test_unchanged_review_is_not_rewritten_on_every_sync(): void
    {
        $this->fakeYandex($this->reviews(3));
        $this->runSync();

        $review = Review::where('external_id', 'review1')->firstOrFail();
        $touchedAt = $review->updated_at;

        $this->travel(2)->minutes();
        $this->runSync();

        $this->assertEquals($touchedAt, $review->fresh()->updated_at, 'неизменившийся отзыв не переписывается');
    }

    public function test_organization_deleted_during_walk_ends_job_quietly(): void
    {
        $this->fakeYandex($this->reviews(120), [
            'reviewsResponse' => function (int $page) {
                if ($page === 2) {
                    // Пользователь нажал «Отключить», пока воркер ходил по страницам.
                    Organization::whereKey($this->organization->id)->delete();
                }

                return null;
            },
        ]);
        $run = $this->organization->syncRuns()->create(['status' => SyncStatus::Queued]);

        $job = (new SyncOrganization($run))->withFakeQueueInteractions();
        $job->handle(app(OrganizationSyncService::class));

        $job->assertNotFailed();
        $job->assertNotReleased();
        $this->assertFalse(SyncRun::whereKey($run->id)->exists());
    }

    public function test_job_for_closed_run_does_nothing(): void
    {
        $run = $this->organization->syncRuns()->create(['status' => SyncStatus::Failed]);
        Http::fake(fn () => throw new \LogicException('не должно быть запросов'));

        $job = (new SyncOrganization($run))->withFakeQueueInteractions();
        $job->handle(app(OrganizationSyncService::class));

        $this->assertSame(SyncStatus::Failed, $run->fresh()->status);
        $job->assertNotFailed();
    }
}
