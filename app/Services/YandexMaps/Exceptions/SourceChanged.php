<?php

namespace App\Services\YandexMaps\Exceptions;

/**
 * Ответ пришёл, но не совпадает с ожидаемой схемой: поменялась разметка,
 * формат JSON или подпись запросов. Повтор не поможет, нужен человек,
 * поэтому такая ошибка не ретраится и пишется в лог как critical.
 */
final class SourceChanged extends SourceException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $message, private readonly array $context = [])
    {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function because(string $reason, array $context = []): self
    {
        return new self("Похоже, Яндекс изменил формат данных: {$reason}", $context);
    }

    public function errorCode(): string
    {
        return 'source_changed';
    }

    public function isRetryable(): bool
    {
        return false;
    }

    public function context(): array
    {
        return $this->context;
    }
}
