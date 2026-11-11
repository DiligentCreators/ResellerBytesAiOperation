import { Link } from '@inertiajs/react';

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type Props = {
    links: PaginationLink[];
};

export function Pagination({ links }: Props) {
    if (links.length <= 3) {
        return null;
    }

    return (
        <nav aria-label="Pagination" className="flex flex-wrap items-center gap-2 border-t p-4">
            {links.map((link, index) => {
                const label = link.label
                    .replaceAll('&laquo;', '‹')
                    .replaceAll('&raquo;', '›')
                    .replaceAll('&hellip;', '…')
                    .trim();

                const className = link.active
                    ? 'rounded-md border bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground'
                    : 'rounded-md border px-3 py-1.5 text-xs hover:bg-muted';

                return link.url ? (
                    <Link
                        key={index}
                        href={link.url}
                        aria-current={link.active ? 'page' : undefined}
                        className={className}
                    >
                        {label}
                    </Link>
                ) : (
                    <span
                        key={index}
                        aria-disabled="true"
                        className="rounded-md border px-3 py-1.5 text-xs opacity-40"
                    >
                        {label}
                    </span>
                );
            })}
        </nav>
    );
}
