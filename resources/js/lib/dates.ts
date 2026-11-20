type FormatOptions = {
    timeZone?: string | null;
    withTime?: boolean;
};

function resolveTimeZone(timeZone?: string | null): string | undefined {
    if (!timeZone || timeZone.trim() === '') {
        return undefined;
    }

    try {
        // Throws RangeError for invalid IANA zones.
        new Intl.DateTimeFormat(undefined, { timeZone }).format(0);

        return timeZone;
    } catch {
        return undefined;
    }
}

/**
 * Format an ISO/API timestamp for display using the user locale.
 * Prefer the workspace timezone when provided.
 */
export function formatDate(
    value: string | null | undefined,
    options: FormatOptions = {},
): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    const timeZone = resolveTimeZone(options.timeZone);

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        ...(options.withTime ? { timeStyle: 'short' as const } : {}),
        ...(timeZone ? { timeZone } : {}),
    }).format(date);
}

export function formatDateTime(
    value: string | null | undefined,
    timeZone?: string | null,
): string {
    return formatDate(value, { timeZone, withTime: true });
}
