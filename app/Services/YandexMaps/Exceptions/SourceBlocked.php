<?php

namespace App\Services\YandexMaps\Exceptions;

/**
 * Яндекс показал капчу или ответил 429. Долбить дальше бессмысленно и вредно:
 * запросы к источнику ставятся на паузу (см. RequestThrottle), задача ждёт её конца.
 *
 * Пауза общая на всех, и пока она идёт, попытка даже не доходит до сети. Такое
 * ожидание не должно тратить бюджет повторов, поэтому здесь же лежит время до
 * снятия паузы: по нему задача решает, когда просыпаться.
 */
final class SourceBlocked extends SourceException
{
    public function __construct(string $message, private readonly int $retryAfter = 0)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'blocked';
    }

    public function isRetryable(): bool
    {
        return true;
    }

    /** Через сколько секунд пауза снимется (0, если неизвестно). */
    public function retryAfter(): int
    {
        return $this->retryAfter;
    }
}
