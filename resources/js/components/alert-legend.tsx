import { useTranslations } from '@/hooks/use-portal';
import { ALERT_SEVERITY_ORDER, alertSeverityStyle } from '@/lib/alert-severity';
import type { AlertSeverity } from '@/types';

interface AlertLegendProps {
    /** Only the severities actually present are listed, so the legend explains the map rather than the vocabulary. */
    severities: AlertSeverity[];
}

/**
 * Explains the warning polygons currently drawn.
 *
 * The colours are a portal display choice, not a national scale: Hydromet has
 * not approved one, so the legend says so in every language rather than
 * implying an official palette.
 */
export function AlertLegend({ severities }: AlertLegendProps) {
    const t = useTranslations();
    const present = ALERT_SEVERITY_ORDER.filter((severity) => severities.includes(severity));

    if (present.length === 0) {
        return null;
    }

    return (
        <div
            className="rounded-lg border bg-card p-3"
            role="group"
            aria-label={t('alert_legend_label')}
        >
            <p className="text-xs font-medium text-muted-foreground">{t('alert_legend_label')}</p>
            <ul className="mt-2 flex flex-wrap gap-x-4 gap-y-2">
                {present.map((severity) => {
                    const style = alertSeverityStyle(severity);

                    return (
                        <li key={severity} className="flex items-center gap-2 text-sm">
                            <span
                                aria-hidden
                                className="size-3 shrink-0 rounded-xs border"
                                style={{ backgroundColor: style.fill, borderColor: style.stroke }}
                            />
                            {t(`alert_severity_${severity.toLowerCase()}`)}
                        </li>
                    );
                })}
            </ul>
            <p className="mt-2 text-xs text-muted-foreground">{t('alert_legend_provisional')}</p>
        </div>
    );
}
