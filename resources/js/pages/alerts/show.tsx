import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, History } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { usePortal, useTranslations } from '@/hooks/use-portal';
import { PublicLayout } from '@/layouts/public-layout';
import { alertSeverityStyle } from '@/lib/alert-severity';
import { formatDateTime } from '@/lib/datetime';
import type { PublicAlertDetail, PublicAlertHistoryEntry } from '@/types';

interface AlertShowProps {
    alert: PublicAlertDetail;
    history: PublicAlertHistoryEntry[];
}

/**
 * One warning, in full.
 *
 * The home page shows what is in force right now. This page exists for the two
 * things that page cannot do: give a warning a URL somebody can send to someone
 * else, and answer "is this still current" for a link that arrives an hour
 * later. A warning that has expired or been superseded is shown, not hidden —
 * a permalink that turns into a 404 reads as "nothing was ever wrong".
 */
export default function AlertShow({ alert, history }: AlertShowProps) {
    const t = useTranslations();
    const { locale, displayTimezone } = usePortal();
    const style = alertSeverityStyle(alert.severity);

    const when = (value: string) => formatDateTime(value, locale.current, displayTimezone);

    return (
        <PublicLayout>
            <Head title={`${alert.headline} — ${t('brand_name')}`} />

            <Button asChild variant="ghost" className="mb-5 -ml-2">
                <Link href="/">
                    <ArrowLeft aria-hidden />
                    {t('alert_back')}
                </Link>
            </Button>

            <article className="space-y-7">
                <header className="space-y-4">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant="secondary">
                            <span
                                aria-hidden
                                className="size-3 shrink-0 rounded-xs border"
                                style={{ backgroundColor: style.fill, borderColor: style.stroke }}
                            />
                            {t(`alert_severity_${alert.severity.toLowerCase()}`)}
                        </Badge>
                        {alert.isMock && (
                            <Badge variant="destructive">{t('mock_data_badge')}</Badge>
                        )}
                    </div>

                    <h1 className="font-heading text-3xl font-semibold text-balance sm:text-4xl">
                        {alert.headline}
                    </h1>

                    {/*
                     * The state comes before the text. Someone arriving from a
                     * forwarded link needs to know whether to act before they
                     * read what to do.
                     */}
                    {!alert.isActive && (
                        <p
                            role="status"
                            className="rounded-lg border border-dashed px-4 py-3 text-sm"
                        >
                            {alert.supersededAt === null
                                ? t('alert_state_expired', { time: when(alert.expiresAt) })
                                : t('alert_state_superseded', {
                                      time: when(alert.supersededAt),
                                  })}
                        </p>
                    )}
                </header>

                <Card>
                    <CardContent className="space-y-6 pt-6">
                        <p className="text-base leading-8 whitespace-pre-wrap">
                            {alert.description}
                        </p>

                        {alert.instruction !== null && (
                            <p className="flex gap-2 rounded-lg border bg-muted px-4 py-3">
                                <AlertTriangle className="mt-1 size-4 shrink-0" aria-hidden />
                                <span className="leading-7">{alert.instruction}</span>
                            </p>
                        )}

                        <dl className="grid gap-4 sm:grid-cols-2">
                            <Fact label={t('alert_effective_from')}>
                                <time dateTime={alert.effectiveAt ?? alert.sentAt}>
                                    {when(alert.effectiveAt ?? alert.sentAt)}
                                </time>
                            </Fact>
                            <Fact label={t('alert_expires_at')}>
                                <time dateTime={alert.expiresAt}>{when(alert.expiresAt)}</time>
                            </Fact>
                            <Fact label={t('alert_areas_label')}>
                                {alert.areas.length === 0
                                    ? t('alert_areas_none')
                                    : alert.areas.map((area) => area.description).join(', ')}
                            </Fact>
                            <Fact label={t('alert_sender')}>{alert.sender}</Fact>
                            {/*
                             * Translated, not passed through. `Expected` and
                             * `Likely` are CAP vocabulary, and leaving them raw
                             * would put English words on a Tajik page — the
                             * same reason severity is translated already.
                             */}
                            <Fact label={t('alert_urgency')}>
                                {t(`alert_urgency_${alert.urgency.toLowerCase()}`)}
                            </Fact>
                            <Fact label={t('alert_certainty')}>
                                {t(`alert_certainty_${alert.certainty.toLowerCase()}`)}
                            </Fact>
                        </dl>
                    </CardContent>
                </Card>

                {/*
                 * Rendered whenever there is more than this message. One entry
                 * is the message itself, and a list of one link to the page you
                 * are already on says nothing.
                 */}
                {history.length > 1 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <History className="size-4" aria-hidden />
                                {t('alert_history_heading')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ol className="space-y-3">
                                {history.map((entry) => {
                                    const isCurrent = entry.identifier === alert.identifier;

                                    return (
                                        <li key={entry.identifier}>
                                            <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                                <span className="text-sm text-muted-foreground">
                                                    <time dateTime={entry.sentAt}>
                                                        {when(entry.sentAt)}
                                                    </time>
                                                </span>
                                                <Badge variant="outline">
                                                    {t(
                                                        `alert_message_type_${entry.messageType.toLowerCase()}`,
                                                    )}
                                                </Badge>
                                                {isCurrent ? (
                                                    <span
                                                        aria-current="true"
                                                        className="font-medium"
                                                    >
                                                        {entry.headline}
                                                    </span>
                                                ) : (
                                                    /*
                                                     * Every entry is addressed
                                                     * under this message's own
                                                     * source: the chain is
                                                     * resolved within one
                                                     * source, so a link can
                                                     * never point at another
                                                     * feed's identifier. The
                                                     * identifier is
                                                     * provider-chosen text and
                                                     * is encoded before it
                                                     * becomes a path segment.
                                                     */
                                                    <Link
                                                        className="font-medium underline underline-offset-4"
                                                        href={`/alerts/${alert.source}/${encodeURIComponent(entry.identifier)}`}
                                                    >
                                                        {entry.headline}
                                                    </Link>
                                                )}
                                            </div>
                                        </li>
                                    );
                                })}
                            </ol>
                        </CardContent>
                    </Card>
                )}
            </article>
        </PublicLayout>
    );
}

function Fact({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="space-y-1">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="text-sm">{children}</dd>
        </div>
    );
}
