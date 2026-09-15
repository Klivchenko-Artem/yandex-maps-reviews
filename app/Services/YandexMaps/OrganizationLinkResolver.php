<?php

namespace App\Services\YandexMaps;

use App\Services\YandexMaps\Exceptions\InvalidOrganizationLink;

/** Ссылка любого поддерживаемого вида → id организации в Яндексе. */
class OrganizationLinkResolver
{
    public function __construct(private readonly YandexMapsClient $client) {}

    public function resolve(string $url): string
    {
        $link = OrganizationLink::parse($url);

        if ($link->isShort()) {
            $link = OrganizationLink::parse($this->client->resolveShortLink($link->shortUrl));

            if ($link->isShort()) {
                throw new InvalidOrganizationLink('Короткая ссылка ведёт на другую короткую ссылку');
            }
        }

        return $link->externalId;
    }
}
