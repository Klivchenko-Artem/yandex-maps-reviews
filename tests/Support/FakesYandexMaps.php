<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Поддельный Яндекс для тестов: карточка из фикстуры реальной формы
 * и API отзывов, которое режет список на страницы как настоящее.
 */
trait FakesYandexMaps
{
    protected const ORG_ID = '1000000001';

    /** @var array<string, mixed> */
    private array $yandexState = [];

    private bool $yandexFaked = false;

    /** @param  array<string, mixed>|null  $ratingData  null означает «Яндекс переименовал блок» */
    protected function orgPageHtml(?array $ratingData = ['ratingCount' => 1234, 'ratingValue' => 4.699999809265137, 'reviewCount' => 3]): string
    {
        $html = file_get_contents(base_path('tests/Fixtures/yandex/org_page.html'));

        if ($ratingData === null) {
            return str_replace('"ratingData":', '"ratingInfo":', $html);
        }

        return preg_replace(
            '/"ratingData": \{[^}]*\}/u',
            '"ratingData": '.json_encode($ratingData),
            $html,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function reviewsFixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/yandex/reviews_page.json')), true);
    }

    /**
     * Отзыв в том виде, в каком его отдаёт fetchReviews.
     *
     * @return array<string, mixed>
     */
    protected function rawReview(int $n, array $overrides = []): array
    {
        return array_replace_recursive([
            'reviewId' => "review{$n}",
            'businessId' => self::ORG_ID,
            'author' => ['name' => "Автор {$n}", 'avatarUrl' => "https://avatars.mds.yandex.net/get-yapic/1/a{$n}/{size}"],
            'text' => "Текст отзыва {$n}",
            'rating' => ($n % 5) + 1,
            // Чем больше номер, тем старше отзыв: выдача Яндекса отсортирована от новых к старым.
            'updatedTime' => gmdate('Y-m-d\TH:i:s.000\Z', strtotime('2026-09-01T00:00:00Z') - $n * 3600),
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $reviews  все отзывы организации, от новых к старым
     * @param  array{ratingData?: array, pageSize?: int, maxPages?: int, redirectOrgTo?: string, reviewsResponse?: callable(int): mixed}  $options
     */
    protected function fakeYandex(array $reviews, array $options = []): void
    {
        // Заглушки Http::fake складываются, и первая совпавшая побеждает. Поэтому колбэк
        // регистрируется один раз и читает текущее состояние: повторный вызов меняет «Яндекс» между парсингами.
        $this->yandexState = [
            'reviews' => $reviews,
            'options' => $options,
            'pageSize' => $options['pageSize'] ?? 50,
            'ratingData' => array_key_exists('ratingData', $options)
                ? $options['ratingData']
                : ['ratingCount' => 1234, 'ratingValue' => 4.699999809265137, 'reviewCount' => count($reviews)],
        ];

        if ($this->yandexFaked) {
            return;
        }
        $this->yandexFaked = true;

        Http::fake(function (Request $request) {
            ['reviews' => $reviews, 'options' => $options, 'pageSize' => $pageSize, 'ratingData' => $ratingData] = $this->yandexState;
            $url = $request->url();

            if (str_contains($url, '/maps/api/business/fetchReviews')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $page = (int) $query['page'];

                if (isset($options['reviewsResponse'])) {
                    $custom = $options['reviewsResponse']($page);
                    if ($custom !== null) {
                        return $custom;
                    }
                }

                return Http::response([
                    'data' => [
                        'reviews' => array_slice($reviews, ($page - 1) * $pageSize, $pageSize),
                        'params' => [
                            'offset' => ($page - 1) * $pageSize,
                            'limit' => $pageSize,
                            'count' => count($reviews),
                            'page' => $page,
                            'totalPages' => (int) ceil(count($reviews) / $pageSize),
                        ],
                    ],
                ]);
            }

            if (str_contains($url, '/maps/org/')) {
                // Склейку дублей Яндекс показывает редиректом на карточку под новым id.
                $redirectTo = $options['redirectOrgTo'] ?? null;
                if ($redirectTo !== null && ! str_contains($url, "/maps/org/{$redirectTo}/")) {
                    return Http::response('', 301, ['Location' => "https://yandex.ru/maps/org/{$redirectTo}/reviews/"]);
                }

                return Http::response($this->orgPageHtml($ratingData), 200, ['Content-Type' => 'text/html']);
            }

            return Http::response('unexpected request: '.$url, 500);
        });
    }
}
