import { Link, usePage } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import type { ReactNode } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { dashboard, home, login, register } from '@/routes';

export default function MarketingLayout({ children }: { children: ReactNode }) {
    const { auth } = usePage().props;

    return (
        <div className="flex min-h-screen flex-col bg-background text-foreground">
            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:rounded-md focus:bg-primary focus:px-3 focus:py-2 focus:text-primary-foreground"
            >
                Skip to content
            </a>
            <header className="sticky top-0 z-40 border-b border-border bg-white/80 backdrop-blur">
                <div className="mx-auto flex h-14 w-full max-w-6xl items-center justify-between px-4 sm:px-6">
                    <Link
                        href={home()}
                        className="flex items-center gap-2.5 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                    >
                        <AppLogoIcon className="size-7 text-brand" />
                        <span className="text-sm font-semibold tracking-tight">
                            ResellerBytes{' '}
                            <span className="font-medium text-muted-foreground">
                                AI Operations
                            </span>
                        </span>
                    </Link>
                    <nav
                        className="hidden items-center gap-6 text-sm font-medium md:flex"
                        aria-label="Marketing"
                    >
                        <Link
                            href="/pricing"
                            className="text-muted-foreground hover:text-foreground"
                        >
                            Pricing
                        </Link>
                        <Link
                            href="/#how-it-works"
                            className="text-muted-foreground hover:text-foreground"
                        >
                            How it works
                        </Link>
                        <Link
                            href="/#security"
                            className="text-muted-foreground hover:text-foreground"
                        >
                            Security
                        </Link>
                    </nav>
                    <div className="flex items-center gap-2">
                        {!auth.user ? (
                            <>
                                <Button variant="ghost" size="sm" asChild>
                                    <Link href={login()}>Sign in</Link>
                                </Button>
                                <Button size="sm" asChild>
                                    <Link href={register()}>
                                        Create account
                                    </Link>
                                </Button>
                            </>
                        ) : (
                            <Button size="sm" asChild>
                                <Link href={dashboard()}>
                                    Open dashboard
                                    <ArrowRight
                                        className="size-4"
                                        aria-hidden
                                    />
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>
            </header>
            <main id="main" className="flex-1">
                {children}
            </main>
            <footer className="border-t border-border bg-surface">
                <div className="mx-auto flex w-full max-w-6xl flex-col gap-4 px-4 py-10 sm:px-6 md:flex-row md:items-center md:justify-between">
                    <div>
                        <p className="text-sm font-semibold">
                            ResellerBytes AI Operations
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            A product of Diligent Creators Pvt. Ltd.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-4 text-sm text-muted-foreground">
                        <Link href="/pricing" className="hover:text-foreground">
                            Pricing
                        </Link>
                        <Link href="/terms" className="hover:text-foreground">
                            Terms of Service
                        </Link>
                        <Link href="/privacy" className="hover:text-foreground">
                            Privacy Policy
                        </Link>
                        <Link href={login()} className="hover:text-foreground">
                            Sign in
                        </Link>
                    </div>
                </div>
            </footer>
        </div>
    );
}
