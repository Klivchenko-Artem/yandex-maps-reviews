import { formatCount, formatRating, plural } from '@/utils/format';
import { describe, expect, it } from 'vitest';

const REVIEWS: [string, string, string] = ['отзыв', 'отзыва', 'отзывов'];

describe('plural', () => {
    it.each([
        [1, 'отзыв'],
        [2, 'отзыва'],
        [5, 'отзывов'],
        [11, 'отзывов'],
        [12, 'отзывов'],
        [21, 'отзыв'],
        [22, 'отзыва'],
        [111, 'отзывов'],
        [5864, 'отзыва'],
    ])('%i → %s', (n, expected) => {
        expect(plural(n, REVIEWS)).toBe(expected);
    });
});

describe('formatCount / formatRating', () => {
    it('разбивает разряды и склоняет', () => {
        expect(formatCount(21229, ['оценка', 'оценки', 'оценок'])).toMatch(/^21\s229 оценок$/);
    });

    it('показывает прочерк, когда оценок нет', () => {
        expect(formatRating(null)).toBe('-');
        expect(formatRating(4.9)).toBe('4,9');
    });
});
