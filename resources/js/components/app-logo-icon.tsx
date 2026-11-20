import type { SVGAttributes } from 'react';

/**
 * Simple SVG mark for ResellerBytes AI Operations — overlapping arcs suggesting
 * diagnostic signal / operations telemetry (not the Laravel starter logo).
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 32 32"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden={props['aria-hidden'] ?? true}
        >
            <rect width="32" height="32" rx="8" fill="currentColor" />
            <path
                d="M8 20.5c3.2-6.4 6.4-9.6 8-9.6s4.8 3.2 8 9.6"
                fill="none"
                stroke="#fff"
                strokeWidth="2.25"
                strokeLinecap="round"
            />
            <circle cx="16" cy="22.5" r="1.75" fill="#fff" />
            <path
                d="M11 14.5c1.6-2.4 3.3-3.6 5-3.6s3.4 1.2 5 3.6"
                fill="none"
                stroke="#fff"
                strokeOpacity="0.55"
                strokeWidth="1.75"
                strokeLinecap="round"
            />
        </svg>
    );
}
