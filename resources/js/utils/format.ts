/** Русская множественная форма: plural(5, ['отзыв', 'отзыва', 'отзывов']) → «отзывов». */
export function plural(n: number, forms: [string, string, string]): string {
    const mod10 = n % 10;
    const mod100 = n % 100;
    if (mod10 === 1 && mod100 !== 11) {
        return forms[0];
    }
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) {
        return forms[1];
    }
    return forms[2];
}

export function formatCount(n: number, forms: [string, string, string]): string {
    return `${n.toLocaleString('ru-RU')} ${plural(n, forms)}`;
}

export function formatRating(rating: number | null): string {
    return rating === null ? '-' : rating.toLocaleString('ru-RU', { minimumFractionDigits: 1, maximumFractionDigits: 2 });
}

export function formatDate(iso: string | null, withTime = false): string {
    if (!iso) {
        return '-';
    }
    return new Date(iso).toLocaleString('ru-RU', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        ...(withTime ? { hour: '2-digit', minute: '2-digit' } : {}),
    });
}
