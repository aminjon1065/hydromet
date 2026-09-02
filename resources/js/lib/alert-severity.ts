import type { AlertSeverity } from '@/types';

/**
 * CAP severity order, most serious first. This ranking comes from CAP 1.2 and
 * is the only ordering the portal claims.
 */
export const ALERT_SEVERITY_ORDER: AlertSeverity[] = [
    'Extreme',
    'Severe',
    'Moderate',
    'Minor',
    'Unknown',
];

interface SeverityStyle {
    stroke: string;
    fill: string;
    fillOpacity: number;
}

/**
 * Provisional map styling for a warning polygon.
 *
 * These are NOT an official Hydromet severity scale — that palette is a
 * BLOCKING input (docs/08-hydromet-input-checklist.md, section 3). They are
 * deliberately neutral, kept in one place so swapping in the approved colours
 * is a single edit, and every screen that uses them also renders the
 * `alert_legend_provisional` note.
 *
 * The values are chosen to stay distinguishable in both the light and dark
 * portal themes and to remain orderable when seen by a viewer with colour
 * vision deficiency, because the ordering is also carried by the legend order
 * and by the textual severity label.
 */
const SEVERITY_STYLES: Record<AlertSeverity, SeverityStyle> = {
    Extreme: { stroke: '#7f1d1d', fill: '#b91c1c', fillOpacity: 0.35 },
    Severe: { stroke: '#9a3412', fill: '#ea580c', fillOpacity: 0.3 },
    Moderate: { stroke: '#854d0e', fill: '#ca8a04', fillOpacity: 0.28 },
    Minor: { stroke: '#1e40af', fill: '#3b82f6', fillOpacity: 0.24 },
    Unknown: { stroke: '#334155', fill: '#64748b', fillOpacity: 0.22 },
};

export function alertSeverityStyle(severity: AlertSeverity): SeverityStyle {
    return SEVERITY_STYLES[severity];
}

/**
 * Severity rank for sorting, highest first. Mirrors AlertSeverity::rank() on
 * the server so a client sorting locally agrees with the API order.
 */
export function alertSeverityRank(severity: AlertSeverity): number {
    const index = ALERT_SEVERITY_ORDER.indexOf(severity);

    return index === -1 ? ALERT_SEVERITY_ORDER.length : index;
}
