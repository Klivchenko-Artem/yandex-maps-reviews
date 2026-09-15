<?php

namespace App\Services\YandexMaps\Data;

use Carbon\CarbonImmutable;

final readonly class ReviewData
{
    public function __construct(
        public string $externalId,
        public string $authorName,
        public ?string $authorAvatarUrl,
        public int $rating,
        public string $text,
        public CarbonImmutable $publishedAt,
        public ?string $businessReply,
    ) {}
}
