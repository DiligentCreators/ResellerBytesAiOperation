import {
    AlertTriangle,
    Info,
    ShieldAlert,
    type LucideIcon,
} from 'lucide-react';
import { cn } from '@/lib/utils';

const severityConfig: Record<
    string,
    { word: string; icon: LucideIcon; className: string }
> = {
    critical: {
        word: 'Critical',
        icon: ShieldAlert,
        className: 'bg-danger-soft text-danger-fg ring-danger/20',
    },
    high: {
        word: 'Critical',
        icon: ShieldAlert,
        className: 'bg-danger-soft text-danger-fg ring-danger/20',
    },
    warning: {
        word: 'Warning',
        icon: AlertTriangle,
        className: 'bg-warning-soft text-warning-fg ring-warning/25',
    },
    medium: {
        word: 'Warning',
        icon: AlertTriangle,
        className: 'bg-warning-soft text-warning-fg ring-warning/25',
    },
    info: {
        word: 'Info',
        icon: Info,
        className: 'bg-info-soft text-info-fg ring-brand/25',
    },
    low: {
        word: 'Info',
        icon: Info,
        className: 'bg-info-soft text-info-fg ring-brand/25',
    },
};

type SeverityBadgeProps = {
    severity: string;
    className?: string;
};

export function severityWord(severity: string): string {
    return (
        severityConfig[severity.toLowerCase()]?.word ??
        severity.replaceAll('_', ' ')
    );
}

export function severityIcon(severity: string): LucideIcon {
    return severityConfig[severity.toLowerCase()]?.icon ?? Info;
}

export function SeverityBadge({ severity, className }: SeverityBadgeProps) {
    const key = severity.toLowerCase();
    const config = severityConfig[key] ?? {
        word: severity.replaceAll('_', ' '),
        icon: Info,
        className: 'bg-muted text-[#334155] ring-border dark:text-foreground',
    };
    const Icon = config.icon;

    return (
        <span
            className={cn(
                'inline-flex h-5 items-center gap-1 rounded-md px-1.5 text-xs font-medium capitalize ring-1 ring-inset',
                config.className,
                className,
            )}
        >
            <Icon className="size-3.5 shrink-0" aria-hidden />
            <span className="sr-only">Severity: </span>
            {config.word}
        </span>
    );
}
