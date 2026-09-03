import { Link } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { usePortal, useTranslations } from '@/hooks/use-portal';
import { alertSeverityStyle } from '@/lib/alert-severity';
import { formatDateTime } from '@/lib/datetime';
import type { PublicAlert } from '@/types';

interface AlertListProps {
    alerts: PublicAlert[];
}

/**
 * The active warnings, as text.
 *
 * This is the accessible route to the same information the polygons show: a
 * Leaflet path is an SVG shape that is not reliably reachable by keyboard or
 * announced by a screen reader, so the map is treated as a visual enhancement
 * and this list as the primary presentation.
 *
 * It renders nothing when there are no warnings — an empty state belongs to the
 * page, which knows whether "no warnings" means "all clear" or "the source has
 * not been connected yet".
 */
export function AlertList({ alerts }: AlertListProps) {
    const t = useTranslations();
    const { locale, displayTimezone } = usePortal();

    if (alerts.length === 0) {
        return null;
    }

    return (
        <ul className="grid gap-4 md:grid-cols-2">
            {alerts.map((alert) => {
                const style = alertSeverityStyle(alert.severity);

                return (
                    <li key={alert.identifier}>
                        <Card className="h-full">
                            <CardHeader>
                                <CardDescription className="flex flex-wrap items-center gap-2">
                                    <span
                                        aria-hidden
                                        className="size-3 shrink-0 rounded-xs border"
                                        style={{
                                            backgroundColor: style.fill,
                                            borderColor: style.stroke,
                                        }}
                                    />
                                    <span>
                                        {t(`alert_severity_${alert.severity.toLowerCase()}`)}
                                    </span>
                                    {alert.isMock && (
                                        <Badge variant="destructive">{t('mock_data_badge')}</Badge>
                                    )}
                                </CardDescription>
                                <CardTitle className="text-base text-balance">
                                    {/*
                                     * The whole card is not the link: a card
                                     * carries several distinct things — an area
                                     * list, a time — and turning all of it into
                                     * one target gives a screen reader a single
                                     * link whose name is the entire card. The
                                     * headline is what the page is about, so
                                     * the headline is the link.
                                     */}
                                    <Link
                                        className="underline-offset-4 hover:underline focus-visible:underline"
                                        href={`/alerts/${alert.source}/${encodeURIComponent(alert.identifier)}`}
                                    >
                                        {alert.headline}
                                    </Link>
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <p className="text-muted-foreground">{alert.description}</p>

                                <dl className="grid gap-1">
                                    <div className="flex flex-wrap gap-x-2">
                                        <dt className="text-muted-foreground">
                                            {t('alert_effective_from')}
                                        </dt>
                                        <dd>
                                            <time dateTime={alert.effectiveAt ?? alert.sentAt}>
                                                {formatDateTime(
                                                    alert.effectiveAt ?? alert.sentAt,
                                                    locale.current,
                                                    displayTimezone,
                                                )}
                                            </time>
                                        </dd>
                                    </div>
                                    <div className="flex flex-wrap gap-x-2">
                                        <dt className="text-muted-foreground">
                                            {t('alert_expires_at')}
                                        </dt>
                                        <dd>
                                            <time dateTime={alert.expiresAt}>
                                                {formatDateTime(
                                                    alert.expiresAt,
                                                    locale.current,
                                                    displayTimezone,
                                                )}
                                            </time>
                                        </dd>
                                    </div>
                                    <div className="flex flex-wrap gap-x-2">
                                        <dt className="text-muted-foreground">
                                            {t('alert_areas_label')}
                                        </dt>
                                        <dd>
                                            {alert.areas.map((area) => area.description).join(', ')}
                                        </dd>
                                    </div>
                                </dl>

                                {alert.instruction !== null && (
                                    <p className="flex gap-2 rounded-lg border bg-muted px-3 py-2">
                                        <AlertTriangle
                                            className="mt-0.5 size-4 shrink-0"
                                            aria-hidden
                                        />
                                        <span>{alert.instruction}</span>
                                    </p>
                                )}

                                <p className="text-xs text-muted-foreground">
                                    {t('alert_sender')}: {alert.sender}
                                </p>
                            </CardContent>
                        </Card>
                    </li>
                );
            })}
        </ul>
    );
}
