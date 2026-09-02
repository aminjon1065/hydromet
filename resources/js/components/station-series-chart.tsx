import { LineChart, type LineSeriesOption } from 'echarts/charts';
import {
    AriaComponent,
    GridComponent,
    TooltipComponent,
    type AriaComponentOption,
    type GridComponentOption,
    type TooltipComponentOption,
} from 'echarts/components';
import * as echarts from 'echarts/core';
import type { ComposeOption } from 'echarts/core';
import { SVGRenderer } from 'echarts/renderers';
import { useEffect, useRef } from 'react';

import { usePortal, useTranslations } from '@/hooks/use-portal';
import { formatDateTime } from '@/lib/datetime';
import type { StationParameter, StationSeries } from '@/types';

type StationChartOption = ComposeOption<
    LineSeriesOption | GridComponentOption | TooltipComponentOption | AriaComponentOption
>;

interface StationSeriesChartProps {
    parameter: StationParameter;
    series?: StationSeries;
}

interface BuildOptionArguments {
    parameter: StationParameter;
    series: StationSeries;
    locale: Parameters<typeof formatDateTime>[1];
    displayTimezone: string;
    unavailableLabel: string;
    ariaDescription: string;
}

const POINT_COLORS = {
    valid: '#0369a1',
    suspect: '#b45309',
    missing: '#64748b',
    corrected: '#7c3aed',
} as const;

// Register only what this one chart needs. Importing the full ECharts bundle
// more than doubled the station-page transfer size.
echarts.use([LineChart, GridComponent, TooltipComponent, AriaComponent, SVGRenderer]);

export function buildStationSeriesOption({
    parameter,
    series,
    locale,
    displayTimezone,
    unavailableLabel,
    ariaDescription,
}: BuildOptionArguments): StationChartOption {
    const labels = series.points.map((point) =>
        formatDateTime(point.time, locale, displayTimezone),
    );
    const data: NonNullable<LineSeriesOption['data']> = series.points.map((point) => ({
        value: point.value,
        itemStyle: { color: POINT_COLORS[point.quality] },
        symbol: point.corrected ? 'diamond' : 'circle',
        symbolSize: point.corrected ? 9 : 6,
    }));

    return {
        animation: false,
        aria: {
            enabled: true,
            description: ariaDescription,
        },
        color: ['#0369a1'],
        grid: { left: 55, right: 18, top: 18, bottom: 70 },
        tooltip: {
            trigger: 'axis',
            valueFormatter: (value) =>
                typeof value === 'number'
                    ? `${value.toFixed(parameter.precision)} ${parameter.unit}`
                    : unavailableLabel,
        },
        xAxis: {
            type: 'category',
            boundaryGap: false,
            data: labels,
            axisLabel: {
                hideOverlap: true,
                rotate: series.points.length > 24 ? 35 : 0,
            },
        },
        yAxis: {
            type: 'value',
            name: parameter.unit,
            nameLocation: 'middle',
            nameGap: 42,
            scale: true,
        },
        series: [
            {
                type: 'line',
                name: parameter.name,
                data,
                connectNulls: false,
                showSymbol: series.points.length <= 72,
                smooth: false,
                lineStyle: { width: 2 },
            },
        ],
    };
}

function EChart({ option, label }: { option: StationChartOption; label: string }) {
    const container = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (container.current === null) {
            return;
        }

        const chart = echarts.init(container.current, undefined, { renderer: 'svg' });
        chart.setOption(option, { notMerge: true, lazyUpdate: true });
        const observer = new ResizeObserver(() => chart.resize());
        observer.observe(container.current);

        return () => {
            observer.disconnect();
            chart.dispose();
        };
    }, [option]);

    return <div ref={container} className="h-full w-full" role="img" aria-label={label} />;
}

export function StationSeriesChart({ parameter, series }: StationSeriesChartProps) {
    const { locale, displayTimezone } = usePortal();
    const t = useTranslations();
    const points = series?.points ?? [];
    const hasValues = points.some((point) => point.value !== null);

    if (!hasValues || series === undefined) {
        return (
            <div className="flex min-h-56 items-center justify-center rounded-lg border border-dashed bg-muted/30 px-5 text-center text-sm text-muted-foreground">
                {t('station_chart_empty')}
            </div>
        );
    }

    const label = t('station_chart_aria', {
        parameter: parameter.name,
        unit: parameter.unit,
    });
    const option = buildStationSeriesOption({
        parameter,
        series,
        locale: locale.current,
        displayTimezone,
        unavailableLabel: t('not_available'),
        ariaDescription: label,
    });

    return (
        <div className="h-80 w-full" data-testid={`chart-${parameter.code}`}>
            <EChart option={option} label={label} />
        </div>
    );
}
