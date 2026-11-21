type FormatOptions = {
    timeZone?: string | null;
    withTime?: boolean;
    /** Append a short timezone label (e.g. "UTC", "GMT+5") when withTime is true. */
    withTimeZoneLabel?: boolean;
};

/** Fixed locale so SSR Node and the browser render identical strings. */
const DISPLAY_LOCALE = 'en-US';

/** Fallback when no workspace/user zone is provided (SSR-stable). */
const DEFAULT_TIME_ZONE = 'UTC';

function resolveTimeZone(timeZone?: string | null): string {
    if (!timeZone || timeZone.trim() === '') {
        return DEFAULT_TIME_ZONE;
    }

    try {
        // Throws RangeError for invalid IANA zones.
        new Intl.DateTimeFormat(DISPLAY_LOCALE, { timeZone }).format(0);
    } catch {
        return DEFAULT_TIME_ZONE;
    }

    return timeZone;
}

function timeZoneLabel(date: Date, timeZone: string): string {
    try {
        const parts = new Intl.DateTimeFormat(DISPLAY_LOCALE, {
            timeZone,
            timeZoneName: 'short',
        }).formatToParts(date);
        const name = parts.find((part) => part.type === 'timeZoneName')?.value;

        return name && name.trim() !== '' ? name : timeZone;
    } catch {
        return timeZone;
    }
}

/**
 * Format an ISO/API timestamp for display.
 * Always uses en-US + an explicit timeZone so SSR and client match.
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

    const formatted = new Intl.DateTimeFormat(DISPLAY_LOCALE, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        ...(options.withTime
            ? { hour: 'numeric', minute: '2-digit' as const }
            : {}),
        timeZone,
    }).format(date);

    if (options.withTime && options.withTimeZoneLabel) {
        return `${formatted} ${timeZoneLabel(date, timeZone)}`;
    }

    return formatted;
}

export function formatDateTime(
    value: string | null | undefined,
    timeZone?: string | null,
): string {
    return formatDate(value, {
        timeZone,
        withTime: true,
        withTimeZoneLabel: true,
    });
}
