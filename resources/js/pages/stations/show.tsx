import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, CalendarRange, Clock3, Download, Gauge, MapPin } from 'lucide-react';

import { StationSeriesChart } from '@/components/station-series-chart';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { usePortal, useTranslations } from '@/hooks/use-portal';
import { PublicLayout } from '@/layouts/public-layout';
import { formatDateTime } from '@/lib/datetime';
import type { PublicSeriesPeriod, StationDetail, StationSeriesRange } from '@/types';

interface StationPageProps {
    station: StationDetail;
    range: StationSeriesRange;
    periods: PublicSeriesPeriod[];
    selectedParameters: string[];
}

function queryUrl(
    stationId: number,
    period: PublicSeriesPeriod,
    parameters: string[],
    exportCsv = false,
): string {
    const search = new URLSearchParams({ period, parameters: parameters.join(',') });
    const suffix = exportCsv ? '/export.csv' : '';

    return `/stations/${stationId}${suffix}?${search.toString()}`;
}

export default function StationShow({
    station,
    range,
    periods,
    selectedParameters,
}: StationPageProps) {
    const { locale, displayTimezone } = usePortal();
    const t = useTranslations();
    const allSelected = selectedParameters.length === station.parameters.length;
    const selected = new Set(selectedParameters);
    const series = new Map(range.series.map((item) => [item.parameter, item]));
    const visibleParameters = station.parameters.filter((parameter) =>
        selected.has(parameter.code),
    );

    return (
        <PublicLayout>
            <Head title={`${station.name} — ${t('brand_name')}`} />

            <Button asChild variant="ghost" className="mb-5 -ml-2">
                <Link href="/">
                    <ArrowLeft aria-hidden />
                    {t('station_back')}
                </Link>
            </Button>

            <section className="space-y-4">
                <div className="flex flex-wrap gap-2">
                    <Badge variant="secondary">{t('station_detail_badge')}</Badge>
                    <Badge variant="outline">{t(`station_status_${station.status}`)}</Badge>
                    {station.isMock && <Badge variant="destructive">{t('mock_data_badge')}</Badge>}
                </div>
                <div>
                    <p className="text-sm font-medium text-muted-foreground">{station.code}</p>
                    <h1 className="font-heading text-3xl font-semibold text-balance sm:text-5xl">
                        {station.name}
                    </h1>
                </div>
                {station.isMock && (
                    <p className="max-w-3xl rounded-lg border border-destructive/20 bg-destructive/5 px-4 py-3 text-sm text-destructive">
                        {t('mock_data_notice')}
                    </p>
                )}
            </section>

            <section
                className="mt-8 grid gap-4 md:grid-cols-2"
                aria-label={t('station_metadata_heading')}
            >
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <MapPin className="size-4" aria-hidden />
                            {t('station_location_heading')}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                            <dt className="text-muted-foreground">{t('station_region')}</dt>
                            <dd>{station.regionCode}</dd>
                            <dt className="text-muted-foreground">{t('station_district')}</dt>
                            <dd>{station.districtCode ?? t('not_available')}</dd>
                            <dt className="text-muted-foreground">{t('station_coordinates')}</dt>
                            <dd>
                                {station.latitude.toFixed(5)}, {station.longitude.toFixed(5)}
                            </dd>
                            <dt className="text-muted-foreground">{t('station_elevation')}</dt>
                            <dd>
                                {station.elevationM === null
                                    ? t('not_available')
                                    : `${station.elevationM} ${t('unit_metres')}`}
                            </dd>
                        </dl>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Gauge className="size-4" aria-hidden />
                            {t('station_source_heading')}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                            <dt className="text-muted-foreground">{t('station_type')}</dt>
                            <dd>{t(`station_type_${station.stationType}`)}</dd>
                            <dt className="text-muted-foreground">{t('station_source')}</dt>
                            <dd>{station.source}</dd>
                            <dt className="text-muted-foreground">{t('station_last_sync')}</dt>
                            <dd>
                                {station.lastSynchronizationAt
                                    ? formatDateTime(
                                          station.lastSynchronizationAt,
                                          locale.current,
                                          displayTimezone,
                                      )
                                    : t('station_not_synchronized')}
                            </dd>
                        </dl>
                    </CardContent>
                </Card>
            </section>

            <section className="mt-10 space-y-5">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h2 className="font-heading text-2xl font-semibold">
                            {t('station_charts_heading')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t('station_quality_notice')}
                        </p>
                    </div>
                    <Button asChild variant="outline">
                        <a
                            href={queryUrl(station.id, range.period, selectedParameters, true)}
                            download
                        >
                            <Download aria-hidden />
                            {t('station_export_csv')}
                        </a>
                    </Button>
                </div>

                <Card>
                    <CardContent className="space-y-5">
                        <div className="space-y-2">
                            <h3 className="flex items-center gap-2 text-sm font-semibold">
                                <CalendarRange className="size-4" aria-hidden />
                                {t('station_period_heading')}
                            </h3>
                            <div className="flex flex-wrap gap-2">
                                {periods.map((period) => (
                                    <Button
                                        key={period}
                                        asChild
                                        variant={period === range.period ? 'default' : 'outline'}
                                        size="sm"
                                    >
                                        <Link
                                            href={queryUrl(station.id, period, selectedParameters)}
                                        >
                                            {t(`period_${period}`)}
                                        </Link>
                                    </Button>
                                ))}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <h3 className="text-sm font-semibold">
                                {t('station_parameters_label')}
                            </h3>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    asChild
                                    variant={allSelected ? 'secondary' : 'outline'}
                                    size="sm"
                                >
                                    <Link
                                        href={queryUrl(
                                            station.id,
                                            range.period,
                                            station.parameters.map((parameter) => parameter.code),
                                        )}
                                    >
                                        {t('station_all_parameters')}
                                    </Link>
                                </Button>
                                {station.parameters.map((parameter) => (
                                    <Button
                                        key={parameter.code}
                                        asChild
                                        variant={
                                            selectedParameters.length === 1 &&
                                            selectedParameters[0] === parameter.code
                                                ? 'secondary'
                                                : 'outline'
                                        }
                                        size="sm"
                                    >
                                        <Link
                                            href={queryUrl(station.id, range.period, [
                                                parameter.code,
                                            ])}
                                        >
                                            {parameter.code}
                                        </Link>
                                    </Button>
                                ))}
                            </div>
                        </div>

                        <div className="flex flex-wrap gap-x-5 gap-y-1 border-t pt-4 text-xs text-muted-foreground">
                            <span className="flex items-center gap-1">
                                <Clock3 className="size-3.5" aria-hidden />
                                {t('station_range_label')}:{' '}
                                {formatDateTime(range.from, locale.current, displayTimezone)} —{' '}
                                {formatDateTime(range.to, locale.current, displayTimezone)}
                            </span>
                            <span>{t(`station_aggregation_${range.aggregation}`)}</span>
                        </div>
                    </CardContent>
                </Card>

                <div className="space-y-5">
                    {visibleParameters.map((parameter) => {
                        const parameterSeries = series.get(parameter.code);
                        const correctedCount = parameterSeries?.points.filter(
                            (point) => point.corrected,
                        ).length;

                        return (
                            <Card key={parameter.code}>
                                <CardHeader>
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <CardTitle>{parameter.name}</CardTitle>
                                            <CardDescription>
                                                {parameter.code} · {parameter.unit}
                                            </CardDescription>
                                        </div>
                                        {correctedCount ? (
                                            <Badge variant="outline">
                                                {t('station_corrected_points', {
                                                    count: String(correctedCount),
                                                })}
                                            </Badge>
                                        ) : null}
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    <StationSeriesChart
                                        parameter={parameter}
                                        series={parameterSeries}
                                    />
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>

                <p className="text-xs text-muted-foreground">{t('station_export_note')}</p>
            </section>
        </PublicLayout>
    );
}
