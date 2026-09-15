<?php

namespace App\Services\YandexMaps;

use App\Services\YandexMaps\Exceptions\InvalidOrganizationLink;

/**
 * Разбор ссылки на карточку организации. Сеть не трогает: короткие ссылки
 * (/maps/-/XXXX) только распознаются, разворачивает их YandexMapsClient.
 *
 * Поддерживаются:
 *  - /maps/org/{slug}/{id}/ и /maps/org/{id}/, в том числе с регионом (/maps/213/moscow/org/...)
 *    и хвостами вроде /reviews/, /gallery/;
 *  - ?oid={id} и poi[uri]=ymapsbm1://org?oid={id};
 *  - /profile/{id}, публичный профиль организации;
 *  - короткие /maps/-/{code}.
 */
final readonly class OrganizationLink
{
    private const HOST_PATTERN = '/^(?:www\.|m\.|maps\.)?yandex\.(?:ru|com|com\.tr|kz|by|uz|az|com\.am|com\.ge|ua)$/';

    private function __construct(
        public ?string $externalId,
        public ?string $shortUrl,
    ) {}

    /** Ссылка в том виде, в каком её стоит хранить и показывать: без пробелов и всегда со схемой. */
    public static function normalize(string $url): string
    {
        $url = trim($url);

        return preg_match('#^https?://#i', $url) ? $url : 'https://'.$url;
    }

    public static function parse(string $url): self
    {
        $url = self::normalize($url);

        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if ($host === '' || ! preg_match(self::HOST_PATTERN, $host)) {
            throw new InvalidOrganizationLink('Ссылка должна вести на Яндекс.Карты (yandex.ru/maps/...)');
        }

        $path = rtrim($parts['path'] ?? '', '/').'/';

        if (preg_match('#^/maps/-/[A-Za-z0-9_~-]+/$#', $path)) {
            return new self(null, 'https://'.$host.rtrim($path, '/'));
        }

        if (preg_match('#/org/(?:[^/]+/)?(\d{5,20})/#', $path, $m)) {
            return new self($m[1], null);
        }

        if (preg_match('#^/profile/(\d{5,20})/#', $path, $m)) {
            return new self($m[1], null);
        }

        $query = urldecode($parts['query'] ?? '');
        if (preg_match('#(?:^|[&?])oid=(\d{5,20})(?:&|$)#', $query, $m)) {
            return new self($m[1], null);
        }

        throw new InvalidOrganizationLink(
            'Не нашли в ссылке организацию. Откройте карточку организации на Яндекс.Картах и скопируйте ссылку из адресной строки или кнопки «Поделиться»'
        );
    }

    public function isShort(): bool
    {
        return $this->shortUrl !== null;
    }
}
