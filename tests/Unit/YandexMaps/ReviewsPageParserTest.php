<?php

namespace Tests\Unit\YandexMaps;

use App\Services\YandexMaps\Exceptions\SourceBlocked;
use App\Services\YandexMaps\Exceptions\SourceChanged;
use App\Services\YandexMaps\Exceptions\SourceUnavailable;
use App\Services\YandexMaps\ReviewsPageParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReviewsPageParserTest extends TestCase
{
    private function fixture(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../Fixtures/yandex/reviews_page.json'), true);
    }

    public function test_parses_reviews_in_real_response_shape(): void
    {
        $page = (new ReviewsPageParser(12))->parse($this->fixture(), 1);

        $this->assertSame(1, $page->availablePages);
        $this->assertSame(3, $page->totalReviews);
        $this->assertTrue($page->isLast());
        $this->assertCount(3, $page->reviews);

        $first = $page->reviews[0];
        $this->assertSame('fixtureReview1', $first->externalId);
        $this->assertSame('Анна К.', $first->authorName);
        $this->assertSame(5, $first->rating);
        $this->assertSame('Отличное место, всё понравилось.', $first->text);
        $this->assertSame('2026-09-14 18:42:52', $first->publishedAt->format('Y-m-d H:i:s'));
        $this->assertStringNotContainsString('{size}', $first->authorAvatarUrl);
        $this->assertNull($first->businessReply);

        $this->assertSame('Спасибо за отзыв, поработаем над скоростью.', $page->reviews[1]->businessReply);
    }

    public function test_available_pages_are_capped_by_yandex_limit(): void
    {
        $response = $this->fixture();
        $response['data']['params']['count'] = 5864;
        $response['data']['params']['totalPages'] = 118;

        $page = (new ReviewsPageParser(12))->parse($response, 12);

        $this->assertSame(12, $page->availablePages);
        $this->assertTrue($page->isLast());
    }

    public function test_anonymous_author_is_allowed(): void
    {
        $response = $this->fixture();
        unset($response['data']['reviews'][2]['author']);

        $page = (new ReviewsPageParser(12))->parse($response, 1);

        $this->assertSame('Аноним', $page->reviews[2]->authorName);
    }

    public function test_captcha_response_is_block(): void
    {
        $this->expectException(SourceBlocked::class);

        (new ReviewsPageParser(12))->parse(['type' => 'captcha', 'captcha' => ['captcha-page' => 'https://yandex.ru/showcaptcha']], 1);
    }

    public function test_api_error_is_temporary_unavailability(): void
    {
        $this->expectException(SourceUnavailable::class);

        (new ReviewsPageParser(12))->parse(['error' => ['code' => 500, 'message' => 'Internal error in /business/fetchReviews']], 13);
    }

    public function test_client_error_inside_json_is_source_change_not_retry(): void
    {
        $this->expectException(SourceChanged::class);

        (new ReviewsPageParser(12))->parse(['error' => ['code' => 400, 'message' => 'Invalid signature']], 1);
    }

    public function test_access_denied_inside_json_is_a_block_not_a_format_change(): void
    {
        // Антибот отвечает и без HTTP-кода. Считать это сменой формата значит завалить
        // лог критическими ошибками и не включить паузу.
        $this->expectException(SourceBlocked::class);

        (new ReviewsPageParser(12))->parse(['error' => ['code' => 403, 'message' => 'Forbidden']], 1);
    }

    public static function brokenResponses(): array
    {
        return [
            'нет data' => [fn (array $r) => ['result' => $r['data']]],
            'reviews переименованы' => [function (array $r) {
                $r['data']['items'] = $r['data']['reviews'];
                unset($r['data']['reviews']);

                return $r;
            }],
            'нет totalPages' => [function (array $r) {
                unset($r['data']['params']['totalPages']);

                return $r;
            }],
            'рейтинг строкой' => [function (array $r) {
                $r['data']['reviews'][0]['rating'] = 'пять';

                return $r;
            }],
            'нет даты' => [function (array $r) {
                unset($r['data']['reviews'][1]['updatedTime']);

                return $r;
            }],
            'дата не дата' => [function (array $r) {
                $r['data']['reviews'][1]['updatedTime'] = 'вчера';

                return $r;
            }],
            'нет id отзыва' => [function (array $r) {
                unset($r['data']['reviews'][0]['reviewId']);

                return $r;
            }],
            // Carbon::parse молча отдаёт «сейчас» на пустую строку, "now" и "Z".
            'дата пустой строкой' => [function (array $r) {
                $r['data']['reviews'][1]['updatedTime'] = '';

                return $r;
            }],
            'дата словом now' => [function (array $r) {
                $r['data']['reviews'][1]['updatedTime'] = 'now';

                return $r;
            }],
            'ответ организации переименован внутри' => [function (array $r) {
                $r['data']['reviews'][1]['businessComment'] = ['body' => 'Спасибо за отзыв'];

                return $r;
            }],
            'аватар не строкой' => [function (array $r) {
                $r['data']['reviews'][0]['author']['avatarUrl'] = ['url' => 'https://example.com/a.jpg'];

                return $r;
            }],
        ];
    }

    #[DataProvider('brokenResponses')]
    public function test_schema_changes_are_detected_not_swallowed(callable $break): void
    {
        $this->expectException(SourceChanged::class);

        (new ReviewsPageParser(12))->parse($break($this->fixture()), 1);
    }

    public function test_empty_middle_page_is_detected(): void
    {
        $response = $this->fixture();
        $response['data']['reviews'] = [];
        $response['data']['params']['count'] = 600;
        $response['data']['params']['totalPages'] = 12;

        $this->expectException(SourceChanged::class);
        $this->expectExceptionMessage('пустая страница 3');

        (new ReviewsPageParser(12))->parse($response, 3);
    }

    public function test_page_where_nobody_has_author_name_is_detected(): void
    {
        $response = $this->fixture();
        $reviews = [];
        for ($i = 0; $i < 6; $i++) {
            $review = $response['data']['reviews'][0];
            $review['reviewId'] = "r{$i}";
            $review['user'] = $review['author'];
            unset($review['author']);
            $reviews[] = $review;
        }
        $response['data']['reviews'] = $reviews;

        $this->expectException(SourceChanged::class);
        $this->expectExceptionMessage('author.name');

        (new ReviewsPageParser(12))->parse($response, 1);
    }
}
