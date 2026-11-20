export function formatMoney(amountMinor: number, currency = 'USD'): string {
    const major = (amountMinor / 100).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    return currency.toUpperCase() === 'USD'
        ? `$${major}`
        : `${currency} ${major}`;
}
