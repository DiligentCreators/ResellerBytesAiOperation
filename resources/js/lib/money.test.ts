import { describe, expect, it } from 'vitest';
import { formatMoney } from './money';

describe('formatMoney', () => {
    it('formats USD amounts in major units with two decimals', () => {
        expect(formatMoney(14900)).toBe('$149.00');
        expect(formatMoney(0, 'USD')).toBe('$0.00');
    });

    it('places a Unicode minus before the currency symbol for credits', () => {
        expect(formatMoney(-4900)).toBe('−$49.00');
        expect(formatMoney(-4900, 'usd')).toBe('−$49.00');
    });

    it('formats non-USD currencies with a space-separated code', () => {
        expect(formatMoney(1000, 'eur')).toBe('EUR 10.00');
        expect(formatMoney(-250, 'GBP')).toBe('−GBP 2.50');
    });
});
