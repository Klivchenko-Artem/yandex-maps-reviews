/**
 * Грубая проверка ссылки до отправки на сервер: отсекает явный мусор, но не пытается
 * повторять разбор бэкенда. Всё, что она пропустила, разберёт `OrganizationLink`.
 */
const YANDEX_HOST = /^(https?:\/\/)?((www|m|maps)\.)?yandex\.[a-z.]{2,6}([/?]|$)/i;

// Организация бывает задана по-разному: /org/ в пути (у турецких карт путь /harita/),
// /profile/, короткая ссылка из «Поделиться» и oid в адресе. При клике по заведению на карте
// oid приезжает внутри poi[uri]=ymapsbm1://org?oid=..., то есть в закодированном виде.
const ORGANIZATION_IN_LINK = /\/org\/|\/profile\/|\/maps\/-\/|[?&]oid=|\boid=\d/i;

export function looksLikeOrganizationLink(value: string): boolean {
    const url = value.trim();

    return YANDEX_HOST.test(url) && ORGANIZATION_IN_LINK.test(decodeSafely(url));
}

/** В адресной строке попадаются и одиночные проценты, на которых decodeURIComponent падает. */
function decodeSafely(url: string): string {
    try {
        return decodeURIComponent(url);
    } catch {
        return url;
    }
}
