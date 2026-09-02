import { render, screen } from '@testing-library/react';
import type { AnchorHTMLAttributes, PropsWithChildren } from 'react';
import { describe, expect, it, vi } from 'vitest';

import type { StationDetail, StationSeriesRange } from '@/types';

import { sharedProps } from './shared-props';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...props }: PropsWithChildren<AnchorHTMLAttributes<HTMLAnchorElement>>) => (
        <a {...props}>{children}</a>
    ),
    usePage: () => ({ props: sharedProps }),
}));

vi.mock('@/components/station-series-chart', () => ({
    StationSeriesChart: ({
        parameter,
        series,
    }: {
        parameter: { code: string };
        series?: unknown;
    }) => (series ? <div data-testid={`chart-${parameter.code}`} /> : <div>Нет значений.</div>),
}));

const { default: StationShow } = await import('@/pages/stations/show');

function translation(key: string): string {
    return sharedProps.translations[key] ?? key;
}

const station: StationDetail = {
    id: 17,
    code: 'FIXTURE-017',
    name: 'Тестовая станция 017',
    latitude: 38.55977,
    longitude: 68.78704,
    elevationM: 812,
    regionCode: 'DUSHANBE',
    districtCode: null,
    status: 'active',
    stationType: 'combined',
    source: 'fixture',
    isMock: true,
    lastSynchronizationAt: '2026-08-31T06:30:02.000000Z',
    parameters: [
        { code: 'PM25', name: 'PM2.5', unit: 'ug/m3', precision: 1 },
        { code: 'TA', name: 'Температура', unit: 'degC', precision: 1 },
    ],
};

const range: StationSeriesRange = {
    from: '2026-08-30T07:00:00.000000Z',
    to: '2026-08-31T07:00:00.000000Z',
    period: '24h',
    aggregation: 'raw',
    series: [
        {
            parameter: 'PM25',
            unit: 'ug/m3',
            precision: 1,
            points: [
                {
                    time: '2026-08-31T05:00:00.000000Z',
                    value: null,
                    quality: 'missing',
                    corrected: false,
                    sampleCount: 1,
                },
                {
                    time: '2026-08-31T06:00:00.000000Z',
                    value: 23.4,
                    quality: 'corrected',
                    corrected: true,
                    sampleCount: 1,
                },
            ],
        },
    ],
};

describe('station detail page', () => {
    it('renders metadata, period filters, selected charts and CSV link', () => {
        render(
            <StationShow
                station={station}
                range={range}
                periods={['24h', '7d', '30d', '1y']}
                selectedParameters={['PM25', 'TA']}
            />,
        );

        expect(
            screen.getByRole('heading', { level: 1, name: 'Тестовая станция 017' }),
        ).toBeInTheDocument();
        expect(screen.getByText('38.55977, 68.78704')).toBeInTheDocument();
        expect(screen.getByText(translation('mock_data_notice'))).toBeInTheDocument();
        expect(screen.getByTestId('chart-PM25')).toBeInTheDocument();
        expect(screen.getByText(translation('station_chart_empty'))).toBeInTheDocument();
        expect(screen.getByText('Исправленных точек: 1')).toBeInTheDocument();

        expect(screen.getByRole('link', { name: '7 дней' })).toHaveAttribute(
            'href',
            '/stations/17?period=7d&parameters=PM25%2CTA',
        );
        expect(screen.getByRole('link', { name: 'Скачать CSV' })).toHaveAttribute(
            'href',
            '/stations/17/export.csv?period=24h&parameters=PM25%2CTA',
        );
    });

    it('allows narrowing the page to one canonical parameter', () => {
        render(
            <StationShow
                station={station}
                range={range}
                periods={['24h', '7d', '30d', '1y']}
                selectedParameters={['PM25']}
            />,
        );

        expect(screen.getByRole('link', { name: 'TA' })).toHaveAttribute(
            'href',
            '/stations/17?period=24h&parameters=TA',
        );
        expect(screen.queryByText('Температура')).not.toBeInTheDocument();
    });
});
