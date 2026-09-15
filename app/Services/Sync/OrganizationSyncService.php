<?php

namespace App\Services\Sync;

use App\Enums\SyncStatus;
use App\Models\Organization;
use App\Models\OrganizationSnapshot;
use App\Models\Review;
use App\Models\ReviewRevision;
use App\Models\SyncRun;
use App\Services\YandexMaps\Data\OrganizationData;
use App\Services\YandexMaps\Data\OrganizationPage;
use App\Services\YandexMaps\Data\ReviewData;
use App\Services\YandexMaps\Data\ReviewsPage;
use App\Services\YandexMaps\Exceptions\SourceChanged;
use App\Services\YandexMaps\Exceptions\SourceException;
use App\Services\YandexMaps\Exceptions\SourceUnavailable;
use App\Services\YandexMaps\YandexMapsClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Полная синхронизация одной организации: карточка → все доступные страницы
 * отзывов → сверка с базой → снимок.
 *
 * Идемпотентна: отзывы опознаются по id Яндекса, повторный прогон обновляет
 * записи, а не плодит новые. Если попытка упала посреди страниц, следующая
 * просто пройдёт всё заново, и уже записанное перезапишется тем же самым.
 */
class OrganizationSyncService
{
    /** Если скачали заметно меньше, чем заявлено на карточке, значит, пагинация сломалась. */
    private const MIN_COMPLETENESS = 0.9;

    /** Поля отзыва, изменения которых попадают в историю. */
    private const TRACKED_FIELDS = ['author_name', 'rating', 'text', 'business_reply'];

    /** Начиная со скольких пропавших ответов подряд считаем, что дело в формате, а не в организации. */
    private const REPLY_LOSS_ALARM = 10;

    public function __construct(
        private readonly YandexMapsClient $client,
        private readonly int $pageSize,
        private readonly int $maxPages,
    ) {}

    public function run(SyncRun $run): void
    {
        $organization = $run->organization;
        $startedAt = CarbonImmutable::now();

        // Новые отзывы копятся между попытками, а не обнуляются: то, что успела записать упавшая
        // попытка, следующая увидит уже существующим и сама не посчитает.
        $createdBefore = $run->reviews_created;

        $run->update([
            'status' => SyncStatus::Running,
            'attempts' => $run->attempts + 1,
            'started_at' => $run->started_at ?? $startedAt,
            'pages_total' => null,
            'pages_done' => 0,
            'reviews_fetched' => 0,
            'reviews_removed' => 0,
            'error_code' => null,
            'error_message' => null,
        ]);

        $requestedExternalId = $organization->external_id;
        $page = $this->client->openOrganization($requestedExternalId);
        $card = $page->organization;

        // Карточку сохраняем сразу, а не в конце обхода: если он сорвётся на капче или
        // недоборе страниц, у организации всё равно будут название, адрес и цифры,
        // а не пустая страница «не удалось загрузить данные».
        $this->storeCard($organization, $card);

        /** @var array<string, CarbonImmutable> $seen id отзыва → дата, уникальные за прогон */
        $seen = [];
        $stats = ['created' => 0];
        $totalReviews = 0;
        $firstPageTotal = null;
        $promisedPages = $this->maxPages;
        $number = 1;

        do {
            $reviewsPage = $this->fetchPage($page, $number, count($seen), $promisedPages);
            $totalReviews = $reviewsPage->totalReviews;
            $promisedPages = $reviewsPage->availablePages;

            if ($firstPageTotal === null) {
                $firstPageTotal = $totalReviews;
                $this->assertCardMatchesReviews($card, $totalReviews);
            }

            $fresh = [];
            foreach ($reviewsPage->reviews as $review) {
                // Дубли бывают и между страницами (выдача сдвинулась), и внутри одной:
                // без этого второй такой же отзыв уходит в INSERT и валит транзакцию по unique.
                if (! isset($seen[$review->externalId]) && ! isset($fresh[$review->externalId])) {
                    $fresh[$review->externalId] = $review;
                }
            }

            DB::transaction(function () use ($organization, $run, $fresh, $startedAt, &$stats) {
                $this->storeReviews($organization, $run, $fresh, $startedAt, $stats);
            });
            foreach ($fresh as $review) {
                $seen[$review->externalId] = $review->publishedAt;
            }

            $run->update([
                'pages_total' => max($reviewsPage->availablePages, 1),
                'pages_done' => $number,
                'reviews_fetched' => count($seen),
                'reviews_created' => $createdBefore + $stats['created'],
                'reviews_updated' => $this->countUpdated($run),
            ]);

            $number++;
        } while (! $reviewsPage->isLast());

        $this->assertComplete($organization, $totalReviews, count($seen));

        $canMarkRemoved = $this->canMarkRemoved($organization, $requestedExternalId, $card->externalId, $firstPageTotal ?? 0, $totalReviews);

        DB::transaction(function () use ($organization, $run, $seen, $totalReviews, $startedAt, $canMarkRemoved) {
            $removed = $canMarkRemoved ? $this->markRemoved($organization, $run, $seen, $totalReviews, $startedAt) : 0;

            $organization->update(['last_synced_at' => $startedAt]);

            OrganizationSnapshot::create([
                'organization_id' => $organization->id,
                'sync_run_id' => $run->id,
                'name' => $organization->name,
                'address' => $organization->address,
                'rating' => $organization->rating,
                'ratings_count' => $organization->ratings_count,
                'reviews_count' => $organization->reviews_count,
                'captured_at' => $startedAt,
            ]);

            $run->update([
                'status' => SyncStatus::Completed,
                'reviews_updated' => $this->countUpdated($run),
                'reviews_removed' => $removed,
                'finished_at' => CarbonImmutable::now(),
            ]);
        });
    }

    /**
     * Ошибка на странице, которую Яндекс сам обещал отдать, при полностью скачанных
     * предыдущих означает не разовый сбой, а опустившийся потолок выдачи. Отличить это
     * при обычном парсинге больше не по чему: конец списка и сбой приходят одинаковой
     * ошибкой. Считать её временной значит впустую отстучать пять попыток по 12 страниц.
     */
    private function fetchPage(OrganizationPage $page, int $number, int $fetched, int $promisedPages): ReviewsPage
    {
        try {
            return $this->client->fetchReviewsPage($page, $number);
        } catch (SourceUnavailable $e) {
            $previousPagesWereFull = $fetched >= $this->pageSize * ($number - 1);

            // Только отказ из тела ответа: сеть, прокси и 502 от чужого балансировщика
            // о потолке выдачи ничего не говорят.
            if ($e->fromApiBody() && $number > 1 && $number <= $promisedPages && $previousPagesWereFull) {
                throw SourceChanged::because(
                    "Яндекс не отдаёт страницу {$number} из обещанных {$promisedPages}: похоже, потолок выдачи опустился до ".($number - 1).' страниц',
                    ['error' => $e->getMessage(), 'max_pages' => $this->maxPages],
                );
            }

            throw $e;
        }
    }

    /**
     * Карточка без блока с рейтингом бывает у новой организации, у которой ещё нет оценок.
     * Но если API отзывов при этом отдаёт сотни отзывов, значит, блок не исчез, а переименован,
     * и «ноль оценок, ноль отзывов» это не правда, а тихая порча данных.
     */
    private function assertCardMatchesReviews(OrganizationData $card, int $totalReviews): void
    {
        if (! $card->hasRatingData() && $totalReviews > 0) {
            throw SourceChanged::because(
                "на карточке нет блока ratingData, а API отзывов отдал {$totalReviews} отзывов",
                ['external_id' => $card->externalId],
            );
        }

        if ($card->hasRatingData() && $card->reviewsCount > 0 && $totalReviews === 0) {
            throw SourceChanged::because("на карточке {$card->reviewsCount} отзывов, а API отзывов говорит 0");
        }
    }

    /**
     * Название, адрес и цифры карточки. Цифр может не быть совсем (новая организация),
     * тогда прежние остаются нетронутыми: перезаписать верный рейтинг нулями хуже,
     * чем показать чуть устаревший.
     */
    private function storeCard(Organization $organization, OrganizationData $card): void
    {
        $attributes = ['name' => $card->name, 'address' => $card->address];

        if ($card->hasRatingData()) {
            $attributes += [
                'rating' => $card->rating,
                'ratings_count' => $card->ratingsCount,
                'reviews_count' => $card->reviewsCount,
            ];
        }

        $this->adoptMergedCard($organization, $card->externalId, $attributes);
    }

    /**
     * Яндекс склеил карточку с другой и отдал её под новым id. Переезжаем на него,
     * иначе ссылка с новым id заведёт вторую организацию с копией тех же отзывов,
     * а пометка удалённых у этой останется выключенной навсегда.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function adoptMergedCard(Organization $organization, string $cardExternalId, array $attributes): void
    {
        if ($cardExternalId !== $organization->external_id) {
            $taken = Organization::query()
                ->where('user_id', $organization->user_id)
                ->where('source', $organization->source)
                ->where('external_id', $cardExternalId)
                ->whereKeyNot($organization->getKey())
                ->exists();

            if ($taken) {
                Log::warning('Карточка склеена с уже подключённой организацией, id не меняем', [
                    'organization_id' => $organization->id,
                    'from' => $organization->external_id,
                    'to' => $cardExternalId,
                ]);
            } else {
                Log::info('Яндекс склеил карточки, переезжаем на новый id', [
                    'organization_id' => $organization->id,
                    'from' => $organization->external_id,
                    'to' => $cardExternalId,
                ]);
                $attributes['external_id'] = $cardExternalId;
            }
        }

        $organization->update($attributes);
    }

    /** Изменённые считаются по журналу ревизий этого запуска, поэтому верно при любом числе попыток. */
    private function countUpdated(SyncRun $run): int
    {
        return ReviewRevision::where('sync_run_id', $run->id)
            ->where('event', ReviewRevision::EVENT_CHANGED)
            ->distinct()
            ->count('review_id');
    }

    /**
     * Пометка удалённых самое опасное место: ошибка тихо прячет живые отзывы.
     * Поэтому пропускаем её в двух сомнительных случаях.
     */
    private function canMarkRemoved(Organization $organization, string $requestedExternalId, string $cardExternalId, int $firstPageTotal, int $lastPageTotal): bool
    {
        // Выдача идёт по смещению. Если за время обхода отзыв добавили или удалили, список сдвинулся
        // и какой-то отзыв мог «проскочить» между страницами. Это не значит, что его удалили.
        if ($firstPageTotal !== $lastPageTotal) {
            Log::info('Число отзывов изменилось во время обхода, удалённые в этот раз не отмечаем', [
                'organization_id' => $organization->id,
                'first_page_total' => $firstPageTotal,
                'last_page_total' => $lastPageTotal,
            ]);

            return false;
        }

        // Яндекс отдал карточку под другим id (склейка дублей). Отзывы, скорее всего, те же,
        // но уверенности нет, а цена ошибки вся история организации в «удалённых». На новый id
        // мы уже переехали, так что со следующего прогона пометка снова работает.
        if ($cardExternalId !== $requestedExternalId) {
            Log::warning('Яндекс отдал карточку с другим id, удалённые в этот раз не отмечаем', [
                'organization_id' => $organization->id,
                'expected' => $requestedExternalId,
                'actual' => $cardExternalId,
            ]);

            return false;
        }

        return true;
    }

    public function markRetrying(SyncRun $run, \Throwable $e, int $delaySeconds): void
    {
        $run->update([
            'status' => SyncStatus::Retrying,
            'error_code' => $e instanceof SourceException ? $e->errorCode() : 'internal',
            'error_message' => $this->publicMessage($e)." Повтор через {$delaySeconds} с.",
        ]);

        Log::warning('Синхронизация организации сорвалась, будет повтор', [
            'sync_run_id' => $run->id,
            'organization_id' => $run->organization_id,
            'delay' => $delaySeconds,
            'error' => $e->getMessage(),
        ]);
    }

    public function markFailed(SyncRun $run, \Throwable $e): void
    {
        $changed = $e instanceof SourceChanged;

        $run->update([
            'status' => $changed ? SyncStatus::SourceChanged : SyncStatus::Failed,
            'error_code' => $e instanceof SourceException ? $e->errorCode() : 'internal',
            'error_message' => $this->publicMessage($e),
            'finished_at' => CarbonImmutable::now(),
        ]);

        $context = [
            'sync_run_id' => $run->id,
            'organization_id' => $run->organization_id,
            'external_id' => $run->organization?->external_id,
            'error' => $e->getMessage(),
            'details' => $e instanceof SourceException ? $e->context() : null,
        ];

        // critical означает, что парсер сломан для всех, а не для одной карточки: на него вешается алерт.
        $changed
            ? Log::critical('Парсер Яндекс.Карт не узнаёт ответ источника', $context)
            : Log::error('Синхронизация организации провалилась', $context);
    }

    /**
     * @param  array<string, ReviewData>  $items
     * @param  array{created: int}  $stats
     */
    private function storeReviews(Organization $organization, SyncRun $run, array $items, CarbonImmutable $now, array &$stats): void
    {
        if ($items === []) {
            return;
        }

        $existing = $organization->reviews()
            ->whereIn('external_id', array_keys($items))
            ->get()
            ->keyBy('external_id');

        $hadReply = 0;
        $lostReply = 0;

        foreach ($items as $item) {
            $attributes = [
                'author_name' => $item->authorName,
                'author_avatar_url' => $item->authorAvatarUrl,
                'rating' => $item->rating,
                'text' => $item->text,
                'business_reply' => $item->businessReply,
                'published_at' => $item->publishedAt,
            ];

            /** @var Review|null $review */
            $review = $existing->get($item->externalId);

            if ($review === null) {
                $organization->reviews()->create($attributes + [
                    'external_id' => $item->externalId,
                    'first_seen_at' => $now,
                ]);
                $stats['created']++;

                continue;
            }

            if ($review->business_reply !== null) {
                $hadReply++;
                $lostReply += $item->businessReply === null ? 1 : 0;
            }

            $review->fill($attributes);

            $changes = $this->trackedChanges($review);
            if ($changes !== []) {
                $this->logRevision($review, $run, ReviewRevision::EVENT_CHANGED, $changes);
            }

            if ($review->removed_at !== null) {
                $wasRemovedAt = $review->removed_at->toIso8601String();
                $review->removed_at = null;
                $this->logRevision($review, $run, ReviewRevision::EVENT_RESTORED, [
                    'removed_at' => ['old' => $wasRemovedAt, 'new' => null],
                ]);
            }

            // UPDATE только тогда, когда есть что менять. Иначе ночное обновление 50 филиалов
            // переписывало бы десятки тысяч неизменившихся строк ради одной служебной даты.
            if ($review->isDirty()) {
                $review->save();
            }
        }

        $this->assertRepliesSurvived($hadReply, $lostReply);
    }

    /**
     * Ответ организации есть у большинства отзывов. Если он разом исчез у всех, у кого был,
     * дело почти наверняка в переименованном поле, а не в том, что организация вычистила
     * ответы за ночь. Падаем до коммита: страница откатится, а история не наполнится
     * ложными «ответ убран».
     */
    private function assertRepliesSurvived(int $hadReply, int $lostReply): void
    {
        if ($hadReply >= self::REPLY_LOSS_ALARM && $lostReply === $hadReply) {
            throw SourceChanged::because("на странице разом пропали все ответы организации ({$hadReply} шт.)");
        }
    }

    /**
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function trackedChanges(Review $review): array
    {
        $changes = [];
        foreach (self::TRACKED_FIELDS as $field) {
            if ($review->isDirty($field)) {
                $changes[$field] = ['old' => $review->getOriginal($field), 'new' => $review->{$field}];
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    private function logRevision(Review $review, SyncRun $run, string $event, array $changes): void
    {
        $review->revisions()->create([
            'organization_id' => $review->organization_id,
            'sync_run_id' => $run->id,
            'event' => $event,
            'changes' => $changes,
        ]);
    }

    /**
     * Отзыв, который был в базе, но не пришёл в этот раз, помечается удалённым.
     *
     * Тонкость: Яндекс отдаёт только последние ~600. Если у организации отзывов больше,
     * старый отзыв «пропадает» просто потому, что вытеснен новыми. Поэтому при неполной
     * выдаче удалёнными считаем только те, что новее самого старого из полученных.
     *
     * @param  array<string, CarbonImmutable>  $seen
     */
    private function markRemoved(Organization $organization, SyncRun $run, array $seen, int $totalReviews, CarbonImmutable $now): int
    {
        // Больше 600 id в whereNotIn не бывает: это потолок выдачи Яндекса.
        $query = $organization->reviews()->visible()->whereNotIn('external_id', array_keys($seen));

        $complete = count($seen) >= $totalReviews;
        if (! $complete) {
            if ($seen === []) {
                return 0;
            }
            $query->where('published_at', '>', min($seen));
        }

        $removed = 0;
        $query->select(['id', 'organization_id', 'removed_at'])->chunkById(200, function ($reviews) use ($run, $now, &$removed) {
            foreach ($reviews as $review) {
                $this->logRevision($review, $run, ReviewRevision::EVENT_REMOVED, [
                    'removed_at' => ['old' => null, 'new' => $now->toIso8601String()],
                ]);
            }
            Review::whereIn('id', $reviews->pluck('id'))->update(['removed_at' => $now]);
            $removed += $reviews->count();
        });

        return $removed;
    }

    /**
     * Проверка на тихую поломку: всё распарсилось, но данных подозрительно мало.
     */
    private function assertComplete(Organization $organization, int $totalReviews, int $fetched): void
    {
        $expected = min($totalReviews, $this->pageSize * $this->maxPages);

        if ($expected > 0 && $fetched < $expected * self::MIN_COMPLETENESS) {
            throw SourceChanged::because("получено {$fetched} отзывов из ожидаемых {$expected}", [
                'organization_id' => $organization->id,
            ]);
        }
    }

    private function publicMessage(\Throwable $e): string
    {
        return $e instanceof SourceException ? $e->getMessage() : 'Внутренняя ошибка при синхронизации';
    }
}
