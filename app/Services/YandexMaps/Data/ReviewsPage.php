<?php

namespace App\Services\YandexMaps\Data;

final readonly class ReviewsPage
{
    /**
     * @param  list<ReviewData>  $reviews
     */
    public function __construct(
        public int $page,
        /** Сколько страниц реально можно получить (Яндекс отдаёт не больше MAX_PAGES). */
        public int $availablePages,
        /** Сколько отзывов у организации всего, по данным самого ответа. */
        public int $totalReviews,
        public array $reviews,
    ) {}

    public function isLast(): bool
    {
        return $this->page >= $this->availablePages;
    }
}
