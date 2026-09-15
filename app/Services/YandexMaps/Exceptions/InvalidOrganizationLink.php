<?php

namespace App\Services\YandexMaps\Exceptions;

final class InvalidOrganizationLink extends SourceException
{
    public function errorCode(): string
    {
        return 'invalid_link';
    }

    public function isRetryable(): bool
    {
        return false;
    }
}
