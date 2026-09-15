<?php

namespace App\Services\YandexMaps;

use App\Services\YandexMaps\Data\ReviewData;
use App\Services\YandexMaps\Data\ReviewsPage;
use App\Services\YandexMaps\Exceptions\SourceBlocked;
use App\Services\YandexMaps\Exceptions\SourceChanged;
use App\Services\YandexMaps\Exceptions\SourceUnavailable;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Разбор ответа /maps/api/business/fetchReviews.
 *
 * Каждое поле, которое мы сохраняем, проверяется на тип. Лучше упасть с понятной
 * причиной «у отзыва пропал updatedTime», чем записать в базу 600 отзывов без дат.
 */
final class ReviewsPageParser
{
    /** Отказ доступа в теле ответа: так антибот отвечает и без HTTP-кода. */
    private const BLOCKING_CODES = [401, 403, 429];

    public function __construct(private readonly int $maxPages) {}

    /**
     * @param  array<string, mixed>  $response
     */
    public function parse(array $response, int $requestedPage): ReviewsPage
    {
        if (($response['type'] ?? null) === 'captcha') {
            throw new SourceBlocked('Яндекс ответил капчей на запрос отзывов');
        }

        if (isset($response['error'])) {
            $error = is_array($response['error']) ? $response['error'] : ['message' => (string) $response['error']];
            $message = $error['message'] ?? 'без описания';
            $code = $error['code'] ?? null;

            // 5xx внутри JSON означает сбой на их стороне, это повторяемо. Так же Яндекс отвечает
            // и за последней страницей выдачи, поэтому пометка «ошибка пришла из тела ответа».
            if (! is_int($code) || $code >= 500) {
                throw SourceUnavailable::fromApi("Яндекс вернул ошибку на странице {$requestedPage}: {$message}");
            }

            // Отказ доступа это не смена формата: надо ждать, а не чинить парсер.
            if (in_array($code, self::BLOCKING_CODES, true)) {
                throw new SourceBlocked("API отзывов отказал в доступе (код {$code}): {$message}");
            }

            throw SourceChanged::because("API отзывов вернул ошибку {$code} на странице {$requestedPage}: {$message}", ['error' => $error]);
        }

        $data = $response['data'] ?? null;
        if (! is_array($data) || ! is_array($data['reviews'] ?? null) || ! is_array($data['params'] ?? null)) {
            throw SourceChanged::because('в ответе fetchReviews нет data.reviews или data.params', [
                'response_keys' => array_keys($response),
                'data_keys' => is_array($data) ? array_keys($data) : null,
            ]);
        }

        $params = $data['params'];
        if (! is_int($params['count'] ?? null) || ! is_int($params['totalPages'] ?? null)) {
            throw SourceChanged::because('в data.params нет count или totalPages', ['params' => $params]);
        }

        $reviews = [];
        $withAuthor = 0;
        foreach (array_values($data['reviews']) as $index => $raw) {
            $reviews[] = $this->parseReview($raw, $requestedPage, $index);
            $withAuthor += is_string($raw['author']['name'] ?? null) ? 1 : 0;
        }

        // Отдельный аноним это норма, а страница, где имени нет ни у кого, означает переименованное поле.
        if (count($reviews) >= 5 && $withAuthor === 0) {
            throw SourceChanged::because("ни у одного отзыва на странице {$requestedPage} нет author.name");
        }

        $availablePages = min($params['totalPages'], $this->maxPages);

        // Страница не последняя, отзывы по счётчику есть, а список пуст. Значит,
        // сломалась пагинация или подпись, а не «отзывы кончились».
        if ($reviews === [] && $requestedPage < $availablePages) {
            throw SourceChanged::because("пустая страница {$requestedPage} из {$availablePages} при {$params['count']} отзывах");
        }

        return new ReviewsPage(
            page: $requestedPage,
            availablePages: $availablePages,
            totalReviews: $params['count'],
            reviews: $reviews,
        );
    }

    private function parseReview(mixed $raw, int $page, int $index): ReviewData
    {
        $where = "отзыв #{$index} на странице {$page}";

        if (! is_array($raw)) {
            throw SourceChanged::because("{$where} не объект");
        }

        $id = $raw['reviewId'] ?? null;
        $author = $raw['author']['name'] ?? null;
        $rating = $raw['rating'] ?? null;
        $text = $raw['text'] ?? null;
        $time = $raw['updatedTime'] ?? null;

        if (! is_string($id) || $id === '') {
            throw SourceChanged::because("{$where}: нет reviewId", ['keys' => array_keys($raw)]);
        }
        if (! is_int($rating) || $rating < 0 || $rating > 5) {
            throw SourceChanged::because("{$where}: rating не число от 0 до 5", ['rating' => $rating]);
        }
        if (! is_string($text)) {
            throw SourceChanged::because("{$where}: text не строка", ['keys' => array_keys($raw)]);
        }

        return new ReviewData(
            externalId: $id,
            // Анонимные отзывы приходят без имени, это нормально, не поломка.
            authorName: is_string($author) && $author !== '' ? $author : 'Аноним',
            authorAvatarUrl: $this->parseAvatar($raw, $where),
            rating: $rating,
            text: $text,
            publishedAt: $this->parseDate($time, $where),
            businessReply: $this->parseReply($raw, $where),
        );
    }

    /**
     * Carbon::parse принимает слишком многое: у пустой строки, "now" и "Z" он молча отдаёт
     * текущее время. Если Яндекс сменит формат даты, такие «сегодняшние» даты разъедут
     * всю историю и сортировку, поэтому сначала требуем узнаваемую дату ISO 8601.
     */
    private function parseDate(mixed $time, string $where): CarbonImmutable
    {
        if (! is_string($time) || preg_match('#^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}#', $time) !== 1) {
            throw SourceChanged::because("{$where}: updatedTime не дата в формате ISO 8601", ['updatedTime' => $time]);
        }

        try {
            return CarbonImmutable::parse($time)->utc();
        } catch (Throwable) {
            throw SourceChanged::because("{$where}: updatedTime не разбирается как дата", ['updatedTime' => $time]);
        }
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function parseAvatar(array $raw, string $where): ?string
    {
        $author = $raw['author'] ?? null;
        if (! is_array($author) || ! array_key_exists('avatarUrl', $author)) {
            return null;
        }

        $avatar = $author['avatarUrl'];
        if ($avatar === null) {
            return null;
        }
        if (! is_string($avatar)) {
            throw SourceChanged::because("{$where}: author.avatarUrl не строка", ['avatar_url' => $avatar]);
        }

        return str_replace('{size}', 'islands-68', $avatar);
    }

    /**
     * Ответ организации проверяем так же строго, как остальные поля: он есть у большинства
     * отзывов, и если разбирать его «как получится», переименованный ключ тихо сотрёт
     * сотни ответов и запишет их в историю как убранные вручную.
     *
     * @param  array<string, mixed>  $raw
     */
    private function parseReply(array $raw, string $where): ?string
    {
        if (! array_key_exists('businessComment', $raw) || $raw['businessComment'] === null) {
            return null;
        }

        $comment = $raw['businessComment'];
        if (! is_array($comment) || ! is_string($comment['text'] ?? null)) {
            throw SourceChanged::because("{$where}: businessComment без текстового поля text", [
                'business_comment' => $comment,
            ]);
        }

        return $comment['text'] === '' ? null : $comment['text'];
    }
}
