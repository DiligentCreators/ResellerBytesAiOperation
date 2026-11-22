type Point = {
    date: string;
    score: number;
    critical_count?: number;
    warning_count?: number;
    info_count?: number;
    metrics?: {
        module_queue_pending?: number | null;
        overdue_unpaid_count?: number | null;
        suspended_with_paid?: number | null;
        sla_breaches?: number | null;
        domains_failing?: number | null;
        disk_free_percent?: number | null;
        heartbeat_state?: string;
    };
};

type Props = {
    points: Point[];
    metric?:
        | 'score'
        | 'critical_count'
        | 'warning_count'
        | 'overdue_unpaid_count'
        | 'disk_free_percent';
    height?: number;
};

function metricValue(point: Point, metric: Props['metric']): number {
    switch (metric) {
        case 'critical_count':
            return point.critical_count ?? 0;
        case 'warning_count':
            return point.warning_count ?? 0;
        case 'overdue_unpaid_count':
            return point.metrics?.overdue_unpaid_count ?? 0;
        case 'disk_free_percent':
            return point.metrics?.disk_free_percent ?? 0;
        default:
            return point.score;
    }
}

/**
 * Simple SVG line chart for health trend history pages.
 */
export function HealthTrendChart({
    points,
    metric = 'score',
    height = 220,
}: Props) {
    const width = 640;
    const padX = 36;
    const padY = 20;

    if (points.length === 0) {
        return (
            <div
                className="flex items-center justify-center rounded-lg border border-dashed border-border text-sm text-muted-foreground"
                style={{ height }}
            >
                No health history in this range yet.
            </div>
        );
    }

    const values = points.map((p) => metricValue(p, metric));
    const min = Math.min(
        ...values,
        metric === 'score' || metric === 'disk_free_percent' ? 0 : 0,
    );
    const maxBase = Math.max(
        ...values,
        metric === 'score' || metric === 'disk_free_percent' ? 100 : 1,
    );
    const max = maxBase === min ? min + 1 : maxBase;
    const span = max - min;

    const coords = points.map((point, index) => {
        const x =
            points.length === 1
                ? width / 2
                : padX + (index / (points.length - 1)) * (width - padX * 2);
        const y =
            padY +
            (1 - (metricValue(point, metric) - min) / span) *
                (height - padY * 2);
        return { x, y, point, value: metricValue(point, metric) };
    });

    const polyline = coords
        .map((c) => `${c.x.toFixed(1)},${c.y.toFixed(1)}`)
        .join(' ');
    const first = coords[0];
    const last = coords[coords.length - 1];
    const area = `${first.x},${height - padY} ${polyline} ${last.x},${height - padY}`;

    return (
        <svg
            viewBox={`0 0 ${width} ${height}`}
            width="100%"
            height={height}
            role="img"
            aria-label={`Trend for ${metric}`}
            className="overflow-visible text-brand"
        >
            <line
                x1={padX}
                y1={height - padY}
                x2={width - padX}
                y2={height - padY}
                className="stroke-border"
                strokeWidth="1"
            />
            <polygon points={area} className="fill-brand/10" />
            <polyline
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinejoin="round"
                strokeLinecap="round"
                points={polyline}
            />
            <text
                x={padX - 8}
                y={padY + 4}
                textAnchor="end"
                className="fill-muted-foreground text-[10px]"
            >
                {Math.round(max)}
            </text>
            <text
                x={padX - 8}
                y={height - padY}
                textAnchor="end"
                className="fill-muted-foreground text-[10px]"
            >
                {Math.round(min)}
            </text>
            <text
                x={padX}
                y={height - 4}
                className="fill-muted-foreground text-[10px]"
            >
                {points[0].date}
            </text>
            <text
                x={width - padX}
                y={height - 4}
                textAnchor="end"
                className="fill-muted-foreground text-[10px]"
            >
                {points[points.length - 1].date}
            </text>
        </svg>
    );
}
