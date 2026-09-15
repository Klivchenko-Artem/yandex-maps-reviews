<?php

namespace App\Services\YandexMaps\Exceptions;

/** Сеть, таймаут, 5xx: временная беда, повтор с бэкоффом обычно лечит. */
final class SourceUnavailable extends SourceException
{
    public function __construct(string $message, private readonly bool $fromApiBody = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Ошибка пришла телом ответа самого API отзывов, а не от сети или прокси.
     * Так Яндекс отвечает и за последней страницей выдачи, поэтому канарейке важно
     * отличать «дальше страниц нет» от «до Яндекса не достучались».
     */
    public static function fromApi(string $message): self
    {
        return new self($message, fromApiBody: true);
    }

    public function fromApiBody(): bool
    {
        return $this->fromApiBody;
    }

    public function errorCode(): string
    {
        return 'unavailable';
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
