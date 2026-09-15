<?php

namespace App\Services\YandexMaps\Data;

final readonly class OrganizationData
{
    public function __construct(
        public string $externalId,
        public string $name,
        public ?string $address,
        /** null, если у организации ещё нет ни одной оценки */
        public ?float $rating,
        /**
         * null означает «на карточке не было блока с рейтингом»: такие цифры
         * не с чем сравнивать, поэтому их не сохраняют поверх прежних.
         */
        public ?int $ratingsCount,
        public ?int $reviewsCount,
    ) {}

    /** Были ли на карточке рейтинг и счётчики. */
    public function hasRatingData(): bool
    {
        return $this->ratingsCount !== null;
    }
}
