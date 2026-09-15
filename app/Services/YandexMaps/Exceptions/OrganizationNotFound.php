<?php

namespace App\Services\YandexMaps\Exceptions;

final class OrganizationNotFound extends SourceException
{
    public static function forId(string $externalId): self
    {
        return new self("Организация {$externalId} не найдена на Яндекс.Картах");
    }

    public function errorCode(): string
    {
        return 'not_found';
    }

    public function isRetryable(): bool
    {
        return false;
    }
}
