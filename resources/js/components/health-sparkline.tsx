type Point = {
    date: string;
    score: number;
};

type Props = {
    points: Point[];
    className?: string;
    height?: number;
};

/**
 * Lightweight SVG sparkline — no chart library dependency.
 */
export function HealthSparkline({ points, className, height = 40 }: Props) {
    if (points.length === 0) {
        return <div className={className} style={{ height }} aria-hidden />;
    }

    const width = 160;
    const pad = 2;
    const scores = points.map((p) => p.score);
    const min = Math.min(...scores, 0);
    const max = Math.max(...scores, 100);
    const span = Math.max(1, max - min);

    const coords = points.map((point, index) => {
        const x =
            points.length === 1
                ? width / 2
                : pad + (index / (points.length - 1)) * (width - pad * 2);
        const y = pad + (1 - (point.score - min) / span) * (height - pad * 2);
        return `${x.toFixed(1)},${y.toFixed(1)}`;
    });

    const polyline = coords.join(' ');
    const last = points[points.length - 1];
    const lastCoord = coords[coords.length - 1]?.split(',') ?? ['0', '0'];

    return (
        <svg
            viewBox={`0 0 ${width} ${height}`}
            width="100%"
            height={height}
            className={className}
            role="img"
            aria-label={`Health score trend, latest ${last.score}`}
        >
            <polyline
                fill="none"
                stroke="currentColor"
                strokeWidth="1.75"
                strokeLinejoin="round"
                strokeLinecap="round"
                points={polyline}
                className="text-brand"
            />
            <circle
                cx={lastCoord[0]}
                cy={lastCoord[1]}
                r="2.5"
                className="fill-brand"
            />
        </svg>
    );
}
