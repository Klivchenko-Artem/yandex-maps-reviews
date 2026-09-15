<?php

namespace App\Services\YandexMaps\Data;

/**
 * Разобранная страница карточки: данные организации и то, без чего не пустят
 * во внутренний API отзывов (csrf-токен и id сессии живут в состоянии страницы).
 */
final class OrganizationPage
{
    public function __construct(
        public readonly OrganizationData $organization,
        /**
         * Не readonly: если Яндекс отвечает на запрос новым токеном, дальше надо
         * ходить уже с ним. Иначе каждая следующая страница снова получит отказ
         * и сходит в Яндекс дважды.
         */
        public string $csrfToken,
        public readonly string $sessionId,
    ) {}
}
