<?php

namespace App\Services\YandexMaps\Exceptions;

use RuntimeException;

/**
 * Базовая ошибка работы с источником. Код ошибки уходит в статус синхронизации
 * и на фронт, поэтому он стабильный и машиночитаемый, а текст для человека.
 */
abstract class SourceException extends RuntimeException
{
    /** Машиночитаемый код: not_found, unavailable, blocked, source_changed, invalid_link. */
    abstract public function errorCode(): string;

    /** Имеет ли смысл повторить попытку позже. */
    abstract public function isRetryable(): bool;

    /** @return array<string, mixed> контекст для лога */
    public function context(): array
    {
        return [];
    }
}
