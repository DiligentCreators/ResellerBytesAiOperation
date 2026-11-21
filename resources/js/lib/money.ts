/** Unicode minus (U+2212) so credits read as "−$49.00", not "$-49.00". */
const UNICODE_MINUS = '\u2212';

/** Fixed locale so SSR Node and the browser render identical strings. */
const DISPLAY_LOCALE = 'en-US';

/**
 * Format a money amount stored in integer minor units.
 * Negative amounts place the Unicode minus before the currency symbol.
 */
export function formatMoney(amountMinor: number, currency = 'USD'): string {
    const negative = amountMinor < 0;
    const major = (Math.abs(amountMinor) / 100).toLocaleString(DISPLAY_LOCALE, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    const formatted =
        currency.toUpperCase() === 'USD'
            ? `$${major}`
            : `${currency.toUpperCase()} ${major}`;

    return negative ? `${UNICODE_MINUS}${formatted}` : formatted;
}
