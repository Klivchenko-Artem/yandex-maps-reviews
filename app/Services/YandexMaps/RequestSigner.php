<?php

namespace App\Services\YandexMaps;

/**
 * Подпись `s` для внутренних запросов Яндекс.Карт (/maps/api/...).
 *
 * Повторяет код фронта карт: параметры сортируются по ключу без учёта регистра,
 * склеиваются как query string (RFC 3986) и хэшируются djb2-вариантом
 * `h = h * 33 ^ code` в беззнаковых 32 битах. Без подписи API отвечает ошибкой,
 * с неверной тоже, поэтому смена алгоритма ловится как «источник изменился».
 */
final class RequestSigner
{
    /**
     * @param  array<string, scalar>  $params
     */
    public function sign(array $params): string
    {
        return (string) $this->hash($this->canonicalQuery($params));
    }

    /**
     * @param  array<string, scalar>  $params
     */
    public function canonicalQuery(array $params): string
    {
        uksort($params, fn (string $a, string $b) => strcmp(strtolower($a), strtolower($b)));

        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = rawurlencode((string) $key).'='.rawurlencode($this->scalarToString($value));
        }

        return implode('&', $pairs);
    }

    private function hash(string $query): int
    {
        $hash = 5381;
        // JS берёт charCodeAt, то есть UTF-16. После rawurlencode строка чисто ASCII,
        // поэтому побайтовый проход даёт тот же результат.
        $length = strlen($query);
        for ($i = 0; $i < $length; $i++) {
            $hash = (($hash * 33) ^ ord($query[$i])) & 0xFFFFFFFF;
        }

        return $hash;
    }

    private function scalarToString(mixed $value): string
    {
        return match (true) {
            $value === true => 'true',
            $value === false => 'false',
            default => (string) $value,
        };
    }
}
