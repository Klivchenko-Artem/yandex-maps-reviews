<?php

namespace Tests\Unit\YandexMaps;

use App\Services\YandexMaps\Exceptions\InvalidOrganizationLink;
use App\Services\YandexMaps\OrganizationLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OrganizationLinkTest extends TestCase
{
    public static function validLinks(): array
    {
        return [
            'slug и id' => ['https://yandex.ru/maps/org/yandeks/1124715036/', '1124715036'],
            'хвост reviews и query' => ['https://yandex.ru/maps/org/yandeks/1124715036/reviews/?ll=37.58%2C55.73&z=17', '1124715036'],
            'регион в пути' => ['https://yandex.ru/maps/213/moscow/org/yandeks/1124715036/gallery/', '1124715036'],
            'без slug' => ['https://yandex.ru/maps/org/1124715036', '1124715036'],
            'без схемы' => ['yandex.ru/maps/org/yandeks/1124715036/', '1124715036'],
            'другой домен' => ['https://yandex.com.tr/maps/org/yandex/1124715036/', '1124715036'],
            'мобильный хост' => ['https://m.yandex.kz/maps/org/1124715036/', '1124715036'],
            'oid в query' => ['https://yandex.ru/maps/?ll=37.58,55.73&mode=search&oid=1124715036&ol=biz&z=17', '1124715036'],
            'poi uri' => ['https://yandex.ru/maps/?poi%5Buri%5D=ymapsbm1%3A%2F%2Forg%3Foid%3D1124715036&z=17', '1124715036'],
            // Клик по заведению на карте города: poi[uri] едет вместе с координатами и режимом.
            'poi uri среди других параметров' => ['https://yandex.ru/maps/67/tomsk/?ll=84.968686%2C56.471694&mode=poi&poi%5Bpoint%5D=84.958383%2C56.473069&poi%5Buri%5D=ymapsbm1%3A%2F%2Forg%3Foid%3D241157280989&z=16', '241157280989'],
            'профиль' => ['https://yandex.ru/profile/1124715036', '1124715036'],
            'пробелы вокруг' => ["  https://yandex.ru/maps/org/yandeks/1124715036/ \n", '1124715036'],
        ];
    }

    #[DataProvider('validLinks')]
    public function test_extracts_organization_id(string $url, string $expected): void
    {
        $link = OrganizationLink::parse($url);

        $this->assertFalse($link->isShort());
        $this->assertSame($expected, $link->externalId);
    }

    public function test_recognizes_short_link_without_network(): void
    {
        $link = OrganizationLink::parse('https://yandex.ru/maps/-/CHUEjI0h');

        $this->assertTrue($link->isShort());
        $this->assertNull($link->externalId);
        $this->assertSame('https://yandex.ru/maps/-/CHUEjI0h', $link->shortUrl);
    }

    public static function invalidLinks(): array
    {
        return [
            'пусто' => [''],
            'не яндекс' => ['https://2gis.ru/taganrog/firm/70000001'],
            'фишинговый домен' => ['https://yandex.ru.evil.com/maps/org/x/1124715036/'],
            'поддомен-обманка' => ['https://evilyandex.ru/maps/org/x/1124715036/'],
            'карта без организации' => ['https://yandex.ru/maps/213/moscow/?ll=37.6,55.7&z=10'],
            'поиск' => ['https://yandex.ru/maps/?text=кофейня'],
            'слишком короткий id' => ['https://yandex.ru/maps/org/x/123/'],
            'мусор' => ['просто текст'],
        ];
    }

    #[DataProvider('invalidLinks')]
    public function test_rejects_links_without_organization(string $url): void
    {
        $this->expectException(InvalidOrganizationLink::class);

        OrganizationLink::parse($url);
    }
}
