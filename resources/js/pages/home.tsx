import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, Clock3, Database, History, MapPin, Radio, Wind } from 'lucide-react';

import { AlertLegend } from '@/components/alert-legend';
import { AlertList } from '@/components/alert-list';
import { StationMap } from '@/components/station-map';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { usePortal, useTranslations } from '@/hooks/use-portal';
import { PublicLayout } from '@/layouts/public-layout';
import { formatDateTime } from '@/lib/datetime';
import type { PublicAlert, PublicStation, StationMeasurement } from '@/types';

interface HomeProps {
    generatedAt: string;
    stations: PublicStation[];
    /**
     * Warnings in force right now. An empty list is a normal state — it means
     * no warning is current, not that the section failed.
     */
    alerts: PublicAlert[];
}

function measurementValue(measurement: StationMeasurement): string {
    if (measurement.value === null) {
        return '—';
    }

    return `${measurement.value.toFixed(measurement.precision)} ${measurement.unit}`;
}

export default function Home({ generatedAt, stations, alerts }: HomeProps) {
    const { locale, displayTimezone } = usePortal();
    const t = useTranslations();
    const alertSeverities = alerts.map((alert) => alert.severity);
    const usesMockAlerts = alerts.some((alert) => alert.isMock);
    const reportingStations = stations.filter((station) => station.observedAt !== null).length;
    const latestObservation = stations
        .map((station) => station.observedAt)
        .filter((value): value is string => value !== null)
        .sort()
        .at(-1);
    const usesMockData = stations.some((station) => station.isMock);

    return (
        <PublicLayout>
            <Head title={t('home_heading')} />

            <section className="space-y-4">
                <div className="flex flex-wrap gap-2">
                    <Badge variant="secondary">{t('home_public_preview_badge')}</Badge>
                    {usesMockData && <Badge variant="destructive">{t('mock_data_badge')}</Badge>}
                </div>
                <h1 className="max-w-4xl font-heading text-3xl font-semibold text-balance sm:text-5xl">
                    {t('home_heading')}
                </h1>
                <p className="max-w-3xl text-base text-muted-foreground">{t('home_intro')}</p>
                {usesMockData && (
                    <p className="max-w-3xl rounded-lg border border-destructive/20 bg-destructive/5 px-4 py-3 text-sm text-destructive">
                        {t('mock_data_notice')}
                    </p>
                )}
            </section>

            <section className="mt-8 grid gap-4 sm:grid-cols-3" aria-label={t('overview_label')}>
                <Card size="sm">
                    <CardHeader>
                        <CardDescription className="flex items-center gap-2">
                            <MapPin className="size-4" aria-hidden />
                            {t('overview_stations')}
                        </CardDescription>
                        <CardTitle className="text-2xl">{stations.length}</CardTitle>
                    </CardHeader>
                </Card>
                <Card size="sm">
                    <CardHeader>
                        <CardDescription className="flex items-center gap-2">
                            <Radio className="size-4" aria-hidden />
                            {t('overview_reporting')}
                        </CardDescription>
                        <CardTitle className="text-2xl">{reportingStations}</CardTitle>
                    </CardHeader>
                </Card>
                <Card size="sm">
                    <CardHeader>
                        <CardDescription className="flex items-center gap-2">
                            <Clock3 className="size-4" aria-hidden />
                            {t('overview_last_observation')}
                        </CardDescription>
                        <CardTitle className="text-base">
                            {latestObservation
                                ? formatDateTime(latestObservation, locale.current, displayTimezone)
                                : t('not_available')}
                        </CardTitle>
                    </CardHeader>
                </Card>
            </section>

            <section className="mt-10 space-y-4" aria-labelledby="active-warnings-heading">
                <div className="flex flex-wrap items-center gap-2">
                    <h2
                        id="active-warnings-heading"
                        className="font-heading text-2xl font-semibold"
                    >
                        {t('alerts_heading')}
                    </h2>
                    {usesMockAlerts && <Badge variant="destructive">{t('mock_data_badge')}</Badge>}
                </div>
                <p className="max-w-3xl text-sm text-muted-foreground">{t('alerts_intro')}</p>

                {alerts.length ? (
                    <>
                        {usesMockAlerts && (
                            <p className="max-w-3xl rounded-lg border border-destructive/20 bg-destructive/5 px-4 py-3 text-sm text-destructive">
                                {t('alerts_mock_notice')}
                            </p>
                        )}
                        <AlertList alerts={alerts} />
                    </>
                ) : (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">{t('alerts_empty_heading')}</CardTitle>
                            <CardDescription>{t('alerts_empty_body')}</CardDescription>
                        </CardHeader>
                    </Card>
                )}

                {/*
                 * Offered whether or not anything is in force. The empty state
                 * says withdrawn and expired warnings remain available through
                 * the history, and this is the link that makes that true; when
                 * warnings are in force, it is still the only way to reach the
                 * ones that are not.
                 */}
                <Button asChild variant="outline">
                    <Link href="/alerts">
                        <History aria-hidden />
                        {t('alert_history_link')}
                    </Link>
                </Button>
            </section>

            {stations.length ? (
                <section className="mt-10 space-y-5">
                    <div>
                        <h2 className="font-heading text-2xl font-semibold">
                            {t('station_map_heading')}
                        </h2>
                        <p className="text-sm text-muted-foreground">{t('station_map_intro')}</p>
                    </div>

                    <StationMap stations={stations} alerts={alerts} />

                    {alerts.length > 0 && <AlertLegend severities={alertSeverities} />}

                    <div className="grid gap-4 lg:grid-cols-2">
                        {stations.map((station) => (
                            <Card key={station.id}>
                                <CardHeader>
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <CardTitle>{station.name}</CardTitle>
                                            <CardDescription>{station.code}</CardDescription>
                                        </div>
                                        <Badge variant="outline">
                                            {t(`station_status_${station.status}`)}
                                        </Badge>
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    {station.measurements.length ? (
                                        <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                            {station.measurements.map((measurement) => (
                                                <div
                                                    key={measurement.parameter}
                                                    className="rounded-lg bg-muted p-3"
                                                >
                                                    <dt className="text-xs font-medium text-muted-foreground">
                                                        {measurement.parameter}
                                                    </dt>
                                                    <dd className="mt-1 text-base font-semibold">
                                                        {measurementValue(measurement)}
                                                    </dd>
                                                    <dd className="mt-1 text-xs text-muted-foreground">
                                                        {t(
                                                            `measurement_quality_${measurement.quality}`,
                                                        )}
                                                    </dd>
                                                </div>
                                            ))}
                                        </dl>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            {t('station_no_measurements')}
                                        </p>
                                    )}
                                    <Button asChild variant="link" className="mt-3 px-0">
                                        <Link href={`/stations/${station.id}`}>
                                            {t('station_view_details')}
                                        </Link>
                                    </Button>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                </section>
            ) : (
                <section className="mt-10">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Database className="size-4" aria-hidden />
                                {t('station_empty_heading')}
                            </CardTitle>
                            <CardDescription>{t('station_empty_body')}</CardDescription>
                        </CardHeader>
                    </Card>
                </section>
            )}

            {/*
             * A status board, not a roadmap. The page above already renders
             * warnings in force and links a working SILAM page, so a section
             * claiming both are still to come contradicted what the visitor
             * could see. What stays true, and stays said, is that the warning
             * data is synthetic until Hydromet supplies an approved feed.
             */}
            <section className="mt-10 space-y-4">
                <div>
                    <h2 className="font-heading text-xl font-semibold">
                        {t('home_integration_status_heading')}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {t('home_integration_status_intro')}
                    </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <AlertTriangle className="size-4" aria-hidden />
                                {t('integration_alerts_heading')}
                                <Badge variant="destructive">
                                    {t('integration_status_fixture_backed')}
                                </Badge>
                            </CardTitle>
                            <CardDescription>{t('integration_alerts_body')}</CardDescription>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Wind className="size-4" aria-hidden />
                                {t('integration_silam_heading')}
                                <Badge variant="secondary">
                                    {t('integration_status_available')}
                                </Badge>
                            </CardTitle>
                            <CardDescription>{t('integration_silam_body')}</CardDescription>
                        </CardHeader>
                    </Card>
                </div>
            </section>

            <section className="mt-10">
                <Card>
                    <CardContent className="space-y-1 text-sm text-muted-foreground">
                        <p>{t('time_timezone_notice', { timezone: displayTimezone })}</p>
                        <p>
                            {t('time_generated_at')}:{' '}
                            <time dateTime={generatedAt}>
                                {formatDateTime(generatedAt, locale.current, displayTimezone)}
                            </time>
                        </p>
                    </CardContent>
                </Card>
            </section>
        </PublicLayout>
    );
}
