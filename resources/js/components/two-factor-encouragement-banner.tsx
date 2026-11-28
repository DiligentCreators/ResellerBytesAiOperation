import { Link, usePage } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { edit } from '@/routes/security';

const STORAGE_KEY = 'rbaio.2fa-banner-dismissed';

function isDismissedForUser(userId: string | number | undefined): boolean {
    if (typeof window === 'undefined' || userId === undefined) {
        return true;
    }

    try {
        return window.localStorage.getItem(`${STORAGE_KEY}.${userId}`) === '1';
    } catch {
        return false;
    }
}

function dismissForUser(userId: string | number | undefined): void {
    if (typeof window === 'undefined' || userId === undefined) {
        return;
    }

    try {
        window.localStorage.setItem(`${STORAGE_KEY}.${userId}`, '1');
    } catch {
        // Ignore quota / private mode errors.
    }
}

/**
 * Soft, dismissible 2FA recommendation. Not shown as a blocking alarm.
 * Workspace Require-2FA enforcement stays in middleware unchanged.
 */
export default function TwoFactorEncouragementBanner() {
    const { auth } = usePage().props;
    const userId = auth.user?.id;
    const [dismissed, setDismissed] = useState(true);

    useEffect(() => {
        setDismissed(isDismissedForUser(userId));
    }, [userId]);

    if (!auth.encourage_two_factor || dismissed) {
        return null;
    }

    return (
        <div
            className="border-b border-border bg-muted/40 px-4 py-2.5 text-sm text-muted-foreground"
            data-testid="two-factor-banner"
            role="status"
        >
            <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3">
                <p>
                    Recommended: turn on two-factor authentication for extra
                    account security.{' '}
                    <Link
                        href={edit()}
                        className="font-medium text-foreground underline underline-offset-4 hover:no-underline"
                    >
                        Set up 2FA
                    </Link>
                </p>
                <button
                    type="button"
                    className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:outline-none"
                    aria-label="Dismiss 2FA recommendation"
                    data-testid="two-factor-banner-dismiss"
                    onClick={() => {
                        dismissForUser(userId);
                        setDismissed(true);
                    }}
                >
                    <X className="size-3.5" aria-hidden />
                    Dismiss
                </button>
            </div>
        </div>
    );
}
