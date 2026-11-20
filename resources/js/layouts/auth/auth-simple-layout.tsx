import { Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center bg-surface p-6 md:p-10">
            <div className="w-full max-w-sm rounded-lg border border-border bg-white p-8 shadow-xs">
                <div className="mb-6 flex flex-col items-center gap-4 text-center">
                    <Link
                        href={home()}
                        className="flex flex-col items-center gap-2 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                    >
                        <AppLogoIcon className="size-9 text-brand" />
                        <span className="sr-only">{title}</span>
                    </Link>
                    <div className="space-y-2">
                        <h1 className="text-xl font-semibold tracking-tight">
                            {title}
                        </h1>
                        {description ? (
                            <p className="text-sm text-muted-foreground">
                                {description}
                            </p>
                        ) : null}
                    </div>
                </div>
                {children}
            </div>
        </div>
    );
}
