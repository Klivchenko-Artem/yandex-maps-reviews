import { looksLikeOrganizationLink } from '@/utils/organizationLink';
import { describe, expect, it } from 'vitest';

describe('looksLikeOrganizationLink', () => {
    it.each([
        'https://yandex.ru/maps/org/kofeynya/1124715036/',
        'https://yandex.ru/maps/213/moscow/org/yandeks/1124715036/reviews/',
        'yandex.ru/maps/org/1124715036',
        'https://yandex.ru/maps/-/CLEqbK~C',
        'https://maps.yandex.ru/?oid=1124715036&ol=biz',
        'https://yandex.com.tr/harita/org/kahve/1124715036/',
        'https://yandex.ru/profile/1124715036',
        // Клик по заведению на карте: организация приезжает внутри poi[uri] в закодированном виде.
        'https://yandex.ru/maps/67/tomsk/?ll=84.9%2C56.4&mode=poi&poi%5Buri%5D=ymapsbm1%3A%2F%2Forg%3Foid%3D241157280989&z=16',
    ])('принимает %s', (url) => {
        expect(looksLikeOrganizationLink(url)).toBe(true);
    });

    it.each([
        '',
        'просто текст',
        'https://2gis.ru/tomsk/firm/241157280989',
        'https://yandex.ru/maps/67/tomsk/',
        'https://yandex.ru/search/?text=кофейня',
        'https://notyandex.ru/maps/org/kofeynya/1124715036/',
    ])('отклоняет %s', (url) => {
        expect(looksLikeOrganizationLink(url)).toBe(false);
    });

    it('не падает на битой кодировке', () => {
        expect(looksLikeOrganizationLink('https://yandex.ru/maps/org/100%/1124715036/')).toBe(true);
    });
});
