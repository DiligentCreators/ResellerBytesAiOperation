import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, Home, ArrowLeft } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { home } from '@/routes';

const copy: Record<number, { title: string; description: string }> = {
    403: {
        title: 'Access denied',
        description:
            'You do not have permission to view this page. If you think this is a mistake, contact a workspace owner or platform admin.',
    },
    404: {
        title: 'Page not found',
        description:
            'That URL does not match any page in ResellerBytes AI Operations.',
    },
    419: {
        title: 'Session expired',
        description:
            'Your session token expired for security. Refresh the page and try again.',
    },
    429: {
        title: 'Too many requests',
        description: 'You have hit a rate limit. Wait a moment, then retry.',
    },
    500: {
        title: 'Something went wrong',
        description:
            'An unexpected server error occurred. Try again shortly. If it persists, contact support with the time of the error.',
    },
    503: {
        title: 'Service unavailable',
        description:
            'The application is temporarily unavailable. Please try again in a few minutes.',
    },
};

type Props = {
    status: number;
};

export default function ErrorPage({ status }: Props) {
    const content = copy[status] ?? {
        title: `Error ${status}`,
        description: 'An unexpected error occurred.',
    };

    return (
        <>
            <Head title={`${status} · ${content.title}`} />
            <div className="flex min-h-svh flex-col items-center justify-center bg-surface px-4 py-16">
                <div className="w-full max-w-md rounded-lg border border-border bg-card p-8 text-center shadow-xs">
                    <div className="mx-auto grid size-10 place-items-center rounded-lg border border-border bg-white text-danger-fg shadow-xs">
                        <AlertTriangle className="size-5" aria-hidden />
                    </div>
                    <p className="mt-4 text-xs font-semibold tracking-wider text-primary uppercase tabular-nums">
                        {status}
                    </p>
                    <h1 className="mt-2 text-xl font-semibold tracking-tight">
                        {content.title}
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {content.description}
                    </p>
                    <div className="mt-6 flex flex-wrap justify-center gap-2">
                        <Button asChild>
                            <Link href={home()}>
                                <Home className="size-4" aria-hidden />
                                Home
                            </Link>
                        </Button>
                        <Button
                            variant="secondary"
                            onClick={() => window.history.back()}
                        >
                            <ArrowLeft className="size-4" aria-hidden />
                            Go back
                        </Button>
                    </div>
                </div>
            </div>
        </>
    );
}
