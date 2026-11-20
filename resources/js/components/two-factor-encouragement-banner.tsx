import { Link, usePage } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import { edit } from '@/routes/security';

export default function TwoFactorEncouragementBanner() {
    const { auth } = usePage().props;

    if (!auth.encourage_two_factor) {
        return null;
    }

    return (
        <div className="border-b border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
            <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3">
                <p className="inline-flex items-center gap-2">
                    <Shield className="size-4 shrink-0" />
                    Protect your workspace with two-factor authentication. It
                    only takes a minute.
                </p>
                <Link
                    href={edit()}
                    className="font-medium underline underline-offset-4 hover:no-underline"
                >
                    Enable 2FA
                </Link>
            </div>
        </div>
    );
}
