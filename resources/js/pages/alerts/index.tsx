import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, History } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { usePortal, useTranslations } from '@/hooks/use-portal';
import { PublicLayout } from '@/layouts/public-layout';
import { alertSeverityStyle } from '@/lib/alert-severity';
import { formatDateTime } from '@/lib/datetime';
import type { PublicAlertHistoryRow } from '@/types';

interface AlertIndexProps {
    alerts: PublicAlertHistoryRow[];
    older: string | null;
    newer: string | null;
}

/**
 * Every published warning, newest first.
 *
 * The overview shows what is in force and drops a warning as soon as it expires
 * or is withdrawn. This is where those go, and the only way to reach one without
 * already knowing its identifier.
 *
 * There are no filters. Region, severity and date-range filters all depend on
 * decisions nobody has made — an approved region vocabulary, a national severity
 * scale, the feed's refresh semantics — so the page offers the one ordering that
 * needs no approval and leaves the rest until it does.
 */
export default function AlertIndex({ alerts, older, newer }: AlertIndexProps) {
    const t = useTranslations();
    const { locale, displayTimezone } = usePortal();

    const when = (value: string) => formatDateTime(value, locale.current, displayTimezone);

    return (
        <PublicLayout>
            <Head title={`${t('alert_history_title')} — ${t('brand_name')}`} />

            <Button asChild variant="ghost" className="mb-5 -ml-2">
                <Link href="/">
                    <ArrowLeft aria-hidden />
                    {t('alert_back')}
                </Link>
            </Button>

            <div className="space-y-7">
                <header className="space-y-3">
                    <h1 className="flex items-center gap-3 font-heading text-3xl font-semibold text-balance sm:text-4xl">
                        <History className="size-7 shrink-0" aria-hidden />
                        {t('alert_history_title')}
                    </h1>
                    <p className="max-w-3xl text-lg leading-8 text-muted-foreground">
                        {t('alert_history_intro')}
                    </p>
                </header>

                {alerts.length === 0 ? (
                    <Card>
                        <CardContent className="pt-6 text-muted-foreground">
                            {t('alert_history_empty')}
                        </CardContent>
                    </Card>
                ) : (
                    <ol className="grid gap-4 md:grid-cols-2">
                        {alerts.map((alert) => {
                            const style = alertSeverityStyle(alert.severity);

                            return (
                                <li key={`${alert.source}/${alert.identifier}`}>
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
                                                    {t(
                                                        `alert_severity_${alert.severity.toLowerCase()}`,
                                                    )}
                                                </span>
                                                <Badge variant="outline">
                                                    {t(
                                                        `alert_message_type_${alert.messageType.toLowerCase()}`,
                                                    )}
                                                </Badge>
                                                {/*
                                                 * The state is the reason this
                                                 * page exists: the same list
                                                 * holds warnings that are in
                                                 * force, ones that ran out and
                                                 * ones that were withdrawn, and
                                                 * they must not look alike.
                                                 */}
                                                <Badge
                                                    variant={
                                                        alert.isActive ? 'default' : 'secondary'
                                                    }
                                                >
                                                    {alert.isActive
                                                        ? t('alert_state_in_force')
                                                        : alert.supersededAt !== null
                                                          ? t('alert_state_withdrawn')
                                                          : t('alert_state_ended')}
                                                </Badge>
                                                {alert.isMock && (
                                                    <Badge variant="destructive">
                                                        {t('mock_data_badge')}
                                                    </Badge>
                                                )}
                                            </CardDescription>
                                            <CardTitle className="text-base text-balance">
                                                <Link
                                                    className="underline-offset-4 hover:underline focus-visible:underline"
                                                    href={`/alerts/${alert.source}/${encodeURIComponent(alert.identifier)}`}
                                                >
                                                    {alert.headline}
                                                </Link>
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent className="space-y-2 text-sm">
                                            <dl className="grid gap-1">
                                                <div className="flex flex-wrap gap-x-2">
                                                    <dt className="text-muted-foreground">
                                                        {t('alert_effective_from')}
                                                    </dt>
                                                    <dd>
                                                        <time
                                                            dateTime={
                                                                alert.effectiveAt ?? alert.sentAt
                                                            }
                                                        >
                                                            {when(
                                                                alert.effectiveAt ?? alert.sentAt,
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
                                                            {when(alert.expiresAt)}
                                                        </time>
                                                    </dd>
                                                </div>
                                                {alert.areas.length > 0 && (
                                                    <div className="flex flex-wrap gap-x-2">
                                                        <dt className="text-muted-foreground">
                                                            {t('alert_areas_label')}
                                                        </dt>
                                                        <dd>{alert.areas.join(', ')}</dd>
                                                    </div>
                                                )}
                                            </dl>
                                        </CardContent>
                                    </Card>
                                </li>
                            );
                        })}
                    </ol>
                )}

                {/*
                 * Plain links, so paging works by keyboard and without
                 * JavaScript. Named for the direction a reader moves: on a list
                 * running newest to oldest, "next" is ambiguous.
                 */}
                {(older !== null || newer !== null) && (
                    <nav
                        className="flex flex-wrap justify-between gap-3"
                        aria-label={t('alert_history_title')}
                    >
                        {newer !== null ? (
                            <Button asChild variant="outline">
                                <Link href={`/alerts?cursor=${encodeURIComponent(newer)}`}>
                                    <ArrowLeft aria-hidden />
                                    {t('alert_history_newer')}
                                </Link>
                            </Button>
                        ) : (
                            <span />
                        )}
                        {older !== null && (
                            <Button asChild variant="outline">
                                <Link href={`/alerts?cursor=${encodeURIComponent(older)}`}>
                                    {t('alert_history_older')}
                                    <ArrowRight aria-hidden />
                                </Link>
                            </Button>
                        )}
                    </nav>
                )}
            </div>
        </PublicLayout>
    );
}
