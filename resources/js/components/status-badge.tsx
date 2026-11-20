import { cn } from '@/lib/utils';

const toneClasses = {
    success: 'bg-success-soft text-success-fg ring-success/20',
    warning: 'bg-warning-soft text-warning-fg ring-warning/25',
    danger: 'bg-danger-soft text-danger-fg ring-danger/20',
    info: 'bg-info-soft text-info-fg ring-brand/25',
    neutral: 'bg-muted text-[#334155] ring-border dark:text-foreground',
} as const;

export type StatusTone = keyof typeof toneClasses;

const statusToneMap: Record<string, StatusTone> = {
    paid: 'success',
    active: 'success',
    healthy: 'success',
    completed: 'success',
    succeeded: 'success',
    verified: 'success',
    trialing: 'info',
    draft: 'info',
    open: 'info',
    scheduled: 'info',
    pending: 'warning',
    processing: 'warning',
    past_due: 'warning',
    degraded: 'warning',
    due: 'warning',
    failed: 'danger',
    overdue: 'danger',
    canceled: 'neutral',
    cancelled: 'neutral',
    void: 'neutral',
    archived: 'neutral',
    incomplete: 'warning',
    critical: 'danger',
    high: 'danger',
    medium: 'warning',
    low: 'info',
    info: 'info',
};

type StatusBadgeProps = {
    status: string;
    tone?: StatusTone;
    className?: string;
    showDot?: boolean;
};

export function statusToTone(status: string): StatusTone {
    return (
        statusToneMap[status.toLowerCase().replaceAll(' ', '_')] ?? 'neutral'
    );
}

export function StatusBadge({
    status,
    tone,
    className,
    showDot = true,
}: StatusBadgeProps) {
    const resolved = tone ?? statusToTone(status);
    const label = status.replaceAll('_', ' ');

    return (
        <span
            className={cn(
                'inline-flex h-5 items-center gap-1 rounded-md px-1.5 text-xs font-medium capitalize ring-1 ring-inset',
                toneClasses[resolved],
                className,
            )}
        >
            {showDot ? (
                <span
                    className="size-1.5 rounded-full bg-current opacity-80"
                    aria-hidden
                />
            ) : null}
            <span className="sr-only">Status: </span>
            {label}
        </span>
    );
}
