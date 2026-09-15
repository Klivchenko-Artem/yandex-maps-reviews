<?php

namespace App\Services\YandexMaps;

use App\Services\YandexMaps\Data\OrganizationData;
use App\Services\YandexMaps\Data\OrganizationPage;
use App\Services\YandexMaps\Exceptions\OrganizationNotFound;
use App\Services\YandexMaps\Exceptions\SourceBlocked;
use App\Services\YandexMaps\Exceptions\SourceChanged;
use JsonException;

/**
 * Достаёт данные из HTML карточки. Вёрстку не парсим вовсе: карты рендерятся
 * из JSON-состояния, которое лежит в <script class="state-view">. Классы и теги
 * у Яндекса меняются часто, а это состояние редко, и его структуру легко проверить.
 */
final class OrganizationPageParser
{
    /**
     * @param  string|null  $redirectedId  id, на который Яндекс увёл нас редиректом:
     *                                     так он показывает карточку, склеенную с нашей.
     */
    public function parse(string $html, string $externalId, ?string $redirectedId = null): OrganizationPage
    {
        $state = $this->extractState($html);

        $config = $state['config'] ?? null;
        $csrfToken = $config['csrfToken'] ?? null;
        $sessionId = $config['counters']['analytics']['sessionId'] ?? null;
        if (! is_string($csrfToken) || $csrfToken === '' || ! is_string($sessionId) || $sessionId === '') {
            throw SourceChanged::because('в состоянии страницы нет csrfToken или sessionId', [
                'config_keys' => is_array($config) ? array_keys($config) : null,
            ]);
        }

        if (! isset($state['stack']) || ! is_array($state['stack'])) {
            throw SourceChanged::because('в состоянии страницы нет stack', ['state_keys' => array_keys($state)]);
        }

        $found = $this->findBusiness($state['stack'], $externalId, $redirectedId);

        if ($found['item'] === null) {
            $this->assertLooksLikeMissingOrganization($found, $externalId);

            throw OrganizationNotFound::forId($externalId);
        }

        return new OrganizationPage($this->toOrganization($found['item']), $csrfToken, $sessionId);
    }

    /**
     * «Организации нет» и «мы разучились читать выдачу» выглядят одинаково: карточки не нашлось.
     * Отличаем по тому, что осталось в ответе. У несуществующего id Яндекс отдаёт панель
     * с пустым списком, поэтому пустой список это честное «не найдено», а вот панель без
     * списка или список без единой карточки означают, что поменялся сам формат.
     *
     * @param  array{item: array<string, mixed>|null, panels: int, items: int, businesses: int}  $found
     */
    private function assertLooksLikeMissingOrganization(array $found, string $externalId): void
    {
        if ($found['panels'] === 0) {
            throw SourceChanged::because('в stack нет ни одной панели с results.items', [
                'external_id' => $externalId,
            ]);
        }

        if ($found['items'] > 0 && $found['businesses'] === 0) {
            throw SourceChanged::because('в выдаче есть элементы, но ни одного с type=business', [
                'external_id' => $externalId,
                'items' => $found['items'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function extractState(string $html): array
    {
        if (trim($html) === '') {
            throw SourceChanged::because('пустой ответ вместо страницы организации');
        }

        $json = $this->extractStateScript($html);
        if ($json === null) {
            // Страница без состояния: либо капча, либо другая вёрстка.
            if (preg_match('#showcaptcha|checkcaptcha|smart-?captcha#i', mb_substr($html, 0, 20000))) {
                throw new SourceBlocked('Яндекс показал капчу вместо карточки организации');
            }

            throw SourceChanged::because('на странице нет блока state-view', [
                'html_head' => mb_substr($html, 0, 300),
            ]);
        }

        try {
            $state = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw SourceChanged::because('state-view не является корректным JSON: '.$e->getMessage());
        }

        if (! is_array($state)) {
            throw SourceChanged::because('state-view не объект');
        }

        return $state;
    }

    /**
     * Содержимое <script class="state-view"> ищем обходом тегов, а не одной регуляркой
     * на всю страницу: состояние крупной карточки весит сотни килобайт, и ленивый `.*?`
     * по такому объёму может упереться в pcre.backtrack_limit. Тогда preg_match вернёт
     * false, и «состояние не нашлось» стало бы неотличимо от смены вёрстки.
     */
    private function extractStateScript(string $html): ?string
    {
        $offset = 0;

        while (($open = stripos($html, '<script', $offset)) !== false) {
            $attributesEnd = strpos($html, '>', $open);
            if ($attributesEnd === false) {
                return null;
            }

            $attributes = substr($html, $open, $attributesEnd - $open);
            $close = stripos($html, '</script>', $attributesEnd);

            // Атрибуты короткие, регулярка по ним безопасна.
            if (preg_match('#\sclass\s*=\s*"[^"]*\bstate-view\b[^"]*"#i', $attributes) === 1) {
                return $close === false
                    ? substr($html, $attributesEnd + 1)
                    : substr($html, $attributesEnd + 1, $close - $attributesEnd - 1);
            }

            $offset = $close === false ? $attributesEnd + 1 : $close + 9;
        }

        return null;
    }

    /**
     * В stack лежат открытые панели карты; карточка организации это элемент
     * results.items с type=business. Ищем строго по id: своему или тому, на который
     * Яндекс увёл редиректом, склеив дубли. Просто «единственную карточку на странице»
     * не берём: вместо удалённой организации Яндекс показывает похожие места,
     * и их отзывы нельзя записывать в нашу.
     *
     * @param  array<mixed>  $stack
     * @return array{item: array<string, mixed>|null, panels: int, items: int, businesses: int}
     */
    private function findBusiness(array $stack, string $externalId, ?string $redirectedId): array
    {
        $result = ['item' => null, 'panels' => 0, 'items' => 0, 'businesses' => 0];
        $wanted = array_filter([$externalId, $redirectedId]);

        foreach ($stack as $panel) {
            $items = $panel['results']['items'] ?? null;
            if (! is_array($items)) {
                continue;
            }

            $result['panels']++;
            $result['items'] += count($items);

            foreach ($items as $item) {
                if (! is_array($item) || ($item['type'] ?? null) !== 'business') {
                    continue;
                }

                $result['businesses']++;
                if ($result['item'] === null && in_array((string) ($item['id'] ?? ''), $wanted, true)) {
                    $result['item'] = $item;
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function toOrganization(array $item): OrganizationData
    {
        $id = $item['id'] ?? null;
        $title = $item['title'] ?? null;
        if (! is_scalar($id) || ! is_string($title) || $title === '') {
            throw SourceChanged::because('у карточки организации нет id или title', ['item_keys' => array_keys($item)]);
        }

        // Блока может не быть у совсем новой организации без единой оценки, это не поломка.
        // Но и «ноль оценок» это тоже не факт: возможно, блок переименовали. Поэтому здесь
        // null, а сверка с числом отзывов из API и решение, сохранять ли цифры, живут в синхронизации.
        $ratingData = $item['ratingData'] ?? null;
        $rating = null;
        $ratingsCount = null;
        $reviewsCount = null;

        if ($ratingData !== null) {
            if (! is_array($ratingData)
                || ! $this->isNumber($ratingData['ratingValue'] ?? null)
                || ! is_int($ratingData['ratingCount'] ?? null)
                || ! is_int($ratingData['reviewCount'] ?? null)) {
                throw SourceChanged::because('ratingData не содержит ratingValue, ratingCount или reviewCount', [
                    'rating_data' => $ratingData,
                ]);
            }

            $ratingsCount = $ratingData['ratingCount'];
            $reviewsCount = $ratingData['reviewCount'];
            // Яндекс отдаёт float32 вроде 4.900000095367432, на карточке показывает 4,9.
            $rating = $ratingsCount > 0 ? round((float) $ratingData['ratingValue'], 2) : null;
        }

        $address = $item['fullAddress'] ?? $item['address'] ?? null;

        return new OrganizationData(
            externalId: (string) $id,
            name: $title,
            address: is_string($address) && $address !== '' ? $address : null,
            rating: $rating,
            ratingsCount: $ratingsCount,
            reviewsCount: $reviewsCount,
        );
    }

    private function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }
}
