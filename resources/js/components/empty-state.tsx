import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type EmptyStateProps = {
    icon: LucideIcon;
    title: string;
    description: string;
    action?: ReactNode;
    className?: string;
    tone?: 'default' | 'danger';
};

export function EmptyState({
    icon: Icon,
    title,
    description,
    action,
    className,
    tone = 'default',
}: EmptyStateProps) {
    return (
        <div
            className={cn(
                'flex flex-col items-center rounded-lg border border-dashed border-input bg-surface/50 px-6 py-16 text-center',
                className,
            )}
        >
            <div
                className={cn(
                    'mb-4 grid size-10 place-items-center rounded-lg border border-border bg-white shadow-xs',
                    tone === 'danger' ? 'text-danger-fg' : 'text-slate-brand',
                )}
            >
                <Icon className="size-5" aria-hidden />
            </div>
            <h3 className="text-sm font-semibold">{title}</h3>
            <p className="mt-1 max-w-xs text-sm text-muted-foreground">
                {description}
            </p>
            {action ? <div className="mt-4">{action}</div> : null}
        </div>
    );
}

export function EmptyStateAction({
    children,
    ...props
}: React.ComponentProps<typeof Button>) {
    return (
        <Button size="sm" {...props}>
            {children}
        </Button>
    );
}
