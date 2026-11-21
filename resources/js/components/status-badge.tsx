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
    trial_started_no_charge: 'info',
    draft: 'info',
    open: 'info',
    scheduled: 'info',
    pending: 'warning',
    processing: 'warning',
    past_due: 'warning',
    degraded: 'warning',
    due: 'warning',
    in_progress: 'warning',
    still_present: 'warning',
    latest_not_analyzed: 'warning',
    same_run: 'info',
    no_newer_run: 'info',
    failed: 'danger',
    overdue: 'danger',
    resolved: 'success',
    absent: 'success',
    verified: 'success',
    completed: 'success',
    approved: 'info',
    pending_approval: 'warning',
    accepted_risk: 'neutral',
    cancelled: 'neutral',
    canceled: 'neutral',
    cancelled: 'neutral',
    void: 'neutral',
    archived: 'neutral',
    incomplete: 'warning',
    critical: 'danger',
    warning: 'warning',
    high: 'danger',
    medium: 'warning',
    low: 'info',
    info: 'info',
};

const statusLabelMap: Record<string, string> = {
    trial_started_no_charge: 'Trial started - no charge',
    in_progress: 'In progress',
    accepted_risk: 'Accepted risk',
    still_present: 'Still present',
    latest_not_analyzed: 'Latest not analyzed',
    same_run: 'Same run',
    no_newer_run: 'No newer run',
    absent: 'Cleared on latest',
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
    const customLabel = statusLabelMap[status.toLowerCase()];
    const label = customLabel ?? status.replaceAll('_', ' ');

    return (
        <span
            className={cn(
                'inline-flex h-5 items-center gap-1 rounded-md px-1.5 text-xs font-medium ring-1 ring-inset',
                customLabel ? null : 'capitalize',
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
