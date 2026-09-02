import { describe, expect, it } from 'vitest';

import { buildStationSeriesOption } from '@/components/station-series-chart';
import type { StationParameter, StationSeries } from '@/types';

const parameter: StationParameter = {
    code: 'PM25',
    name: 'PM2.5',
    unit: 'ug/m3',
    precision: 1,
};

const series: StationSeries = {
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
};

describe('station series chart option', () => {
    it('keeps missing observations null and marks corrected points', () => {
        const option = buildStationSeriesOption({
            parameter,
            series,
            locale: 'en',
            displayTimezone: 'Asia/Dushanbe',
            unavailableLabel: 'Not available',
            ariaDescription: 'PM2.5 chart',
        });
        const chartSeries = Array.isArray(option.series) ? option.series[0] : undefined;
        const data = chartSeries && 'data' in chartSeries ? chartSeries.data : undefined;

        expect(Array.isArray(data) && data[0]).toMatchObject({ value: null, symbol: 'circle' });
        expect(Array.isArray(data) && data[1]).toMatchObject({ value: 23.4, symbol: 'diamond' });
        expect(option.aria).toMatchObject({ enabled: true, description: 'PM2.5 chart' });
    });
});
