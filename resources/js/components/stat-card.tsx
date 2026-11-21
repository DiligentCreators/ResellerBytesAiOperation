import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

type StatCardProps = {
    label: string;
    value: string | number;
    description?: string;
    icon?: LucideIcon;
    className?: string;
};

export function StatCard({
    label,
    value,
    description,
    icon: Icon,
    className,
}: StatCardProps) {
    return (
        <div
            className={cn(
                'rounded-lg border border-border bg-card p-5 shadow-xs',
                className,
            )}
        >
            <p className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                {Icon ? (
                    <Icon className="size-3.5 text-slate-brand" aria-hidden />
                ) : null}
                {label}
            </p>
            <p className="mt-2 text-2xl font-semibold tracking-tight tabular-nums">
                {typeof value === 'number' ? value.toLocaleString() : value}
            </p>
            {description ? (
                <p className="mt-1 text-xs text-muted-foreground">
                    {description}
                </p>
            ) : null}
        </div>
    );
}
