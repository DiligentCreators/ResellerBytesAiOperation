import { describe, expect, it } from 'vitest';
import { formatDate, formatDateTime } from './dates';

describe('formatDate', () => {
    it('renders a stable en-US medium date regardless of default locale', () => {
        expect(formatDate('2026-12-04T12:00:00Z')).toBe('Dec 4, 2026');
        expect(formatDate('2026-11-20T12:00:00Z')).toBe('Nov 20, 2026');
    });

    it('uses the provided IANA timezone for the calendar day', () => {
        // 2026-12-04 02:00 UTC is still Dec 3 in America/Los_Angeles.
        expect(
            formatDate('2026-12-04T02:00:00Z', {
                timeZone: 'America/Los_Angeles',
            }),
        ).toBe('Dec 3, 2026');
    });

    it('falls back to an em dash for empty or invalid values', () => {
        expect(formatDate(null)).toBe('—');
        expect(formatDate(undefined)).toBe('—');
        expect(formatDate('')).toBe('—');
        expect(formatDate('not-a-date')).toBe('—');
    });

    it('falls back to UTC for invalid timezones', () => {
        expect(
            formatDate('2026-12-04T12:00:00Z', { timeZone: 'Not/AZone' }),
        ).toBe('Dec 4, 2026');
    });
});

describe('formatDateTime', () => {
    it('includes a stable en-US short time in UTC by default', () => {
        expect(formatDateTime('2026-12-04T15:30:00Z')).toBe(
            'Dec 4, 2026, 3:30 PM',
        );
    });
});
