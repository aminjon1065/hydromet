import { render, screen } from '@testing-library/react';
import type { AnchorHTMLAttributes, PropsWithChildren } from 'react';
import { describe, expect, it, vi } from 'vitest';

import type { PublicAlert, PublicStation } from '@/types';

import { sharedProps } from './shared-props';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...props }: PropsWithChildren<AnchorHTMLAttributes<HTMLAnchorElement>>) => (
        <a {...props}>{children}</a>
    ),
    usePage: () => ({ props: sharedProps }),
}));

vi.mock('react-leaflet', () => ({
    MapContainer: ({ children }: PropsWithChildren) => (
        <div data-testid="station-map">{children}</div>
    ),
    TileLayer: () => null,
    CircleMarker: ({ children }: PropsWithChildren) => <div>{children}</div>,
    Tooltip: ({ children }: PropsWithChildren) => <span>{children}</span>,
    Popup: ({ children }: PropsWithChildren) => <div>{children}</div>,
    GeoJSON: ({ children }: PropsWithChildren) => <div data-testid="alert-polygon">{children}</div>,
}));

const { default: Home } = await import('@/pages/home');

function translation(key: string): string {
    return sharedProps.translations[key] ?? key;
}

const stations: PublicStation[] = [
    {
        id: 1,
        code: 'FIXTURE-001',
        name: 'Тестовая станция 001',
        latitude: 38.5,
        longitude: 68.7,
        status: 'active',
        source: 'fixture',
        isMock: true,
        observedAt: '2026-08-31T06:00:00.000000Z',
        measurements: [
            {
                parameter: 'PM25',
                value: 23.4,
                unit: 'ug/m3',
                precision: 1,
                quality: 'valid',
                observedAt: '2026-08-31T06:00:00.000000Z',
            },
            {
                parameter: 'RH',
                value: null,
                unit: '%',
                precision: 0,
                quality: 'missing',
                observedAt: '2026-08-31T06:00:00.000000Z',
            },
        ],
    },
];

const alerts: PublicAlert[] = [
    {
        identifier: 'fixture-alert-0001',
        source: 'fixture',
        isMock: true,
        eventCode: 'FIXTURE_HEAVY_RAIN',
        severity: 'Severe',
        urgency: 'Expected',
        certainty: 'Likely',
        sender: 'fixture-warning-desk',
        headline: 'Тестовое предупреждение: сильный дождь',
        description: 'Демонстрационные данные.',
        instruction: 'Это демонстрационный текст.',
        sentAt: '2026-01-15T05:00:00.000000Z',
        effectiveAt: '2026-01-15T05:00:00.000000Z',
        onsetAt: null,
        expiresAt: '2030-01-01T00:00:00.000000Z',
        areas: [
            {
                description: 'Тестовый регион A',
                geocodes: [{ name: 'FIXTURE_REGION', value: 'FIXTURE-REGION-A' }],
                geometry: {
                    type: 'Polygon',
                    coordinates: [
                        [
                            [68.4, 38.3],
                            [69.0, 38.3],
                            [69.0, 38.8],
                            [68.4, 38.8],
                            [68.4, 38.3],
                        ],
                    ],
                },
            },
        ],
    },
];

describe('home page warnings', () => {
    it('draws a polygon and lists the warning in an accessible section', () => {
        render(<Home generatedAt="2026-08-31T06:05:00Z" stations={stations} alerts={alerts} />);

        expect(screen.getByTestId('alert-polygon')).toBeInTheDocument();
        expect(
            screen.getByRole('heading', { level: 2, name: translation('alerts_heading') }),
        ).toBeInTheDocument();
        // The list is the keyboard- and screen-reader-reachable copy of what the
        // polygons show, so the headline must be present outside the map too.
        expect(
            screen.getAllByText('Тестовое предупреждение: сильный дождь').length,
        ).toBeGreaterThan(0);
        expect(screen.getAllByText(translation('alert_severity_severe')).length).toBeGreaterThan(0);
    });

    /**
     * The list is where a warning is opened. Without this the page can say what
     * is in force but never what was issued, and the empty state's promise that
     * withdrawn warnings stay reachable through the history has nowhere to lead.
     */
    it('opens each warning at its own address', () => {
        render(<Home generatedAt="2026-08-31T06:05:00Z" stations={stations} alerts={alerts} />);

        expect(
            screen.getByRole('link', { name: 'Тестовое предупреждение: сильный дождь' }),
        ).toHaveAttribute('href', '/alerts/fixture/fixture-alert-0001');
    });

    /**
     * Offered whether or not anything is in force: the empty state promises
     * that withdrawn and expired warnings stay reachable through the history,
     * and when warnings *are* in force this link is still the only way to the
     * ones that are not.
     */
    it.each([
        ['with warnings in force', alerts],
        ['with nothing in force', [] as PublicAlert[]],
    ])('offers the published warning history %s', (_case, current) => {
        render(<Home generatedAt="2026-08-31T06:05:00Z" stations={stations} alerts={current} />);

        expect(
            screen.getByRole('link', { name: translation('alert_history_link') }),
        ).toHaveAttribute('href', '/alerts');
    });

    it('labels demonstration warnings so they cannot be mistaken for official ones', () => {
        render(<Home generatedAt="2026-08-31T06:05:00Z" stations={stations} alerts={alerts} />);

        expect(screen.getByText(translation('alerts_mock_notice'))).toBeInTheDocument();
    });

    it('shows the severity legend with its provisional-palette note', () => {
        render(<Home generatedAt="2026-08-31T06:05:00Z" stations={stations} alerts={alerts} />);

        expect(
            screen.getByRole('group', { name: translation('alert_legend_label') }),
        ).toBeInTheDocument();
        expect(screen.getByText(translation('alert_legend_provisional'))).toBeInTheDocument();
    });

    it('keeps the station map working when no warning is in force', () => {
        render(<Home generatedAt="2026-08-31T06:05:00Z" stations={stations} alerts={[]} />);

        // The absence of warnings must never remove the station map.
        expect(screen.getByTestId('station-map')).toBeInTheDocument();
        expect(screen.queryByTestId('alert-polygon')).not.toBeInTheDocument();
        expect(screen.getByText(translation('alerts_empty_heading'))).toBeInTheDocument();
        expect(
            screen.queryByRole('group', { name: translation('alert_legend_label') }),
        ).not.toBeInTheDocument();
    });

    it('renders warning text as text, never as markup', () => {
        const hostile: PublicAlert[] = [
            {
                ...alerts[0]!,
                headline: '<img src=x onerror="alert(1)">Опасность',
                description: '<script>alert(2)</script>Описание',
            },
        ];

        const { container } = render(
            <Home generatedAt="2026-08-31T06:05:00Z" stations={stations} alerts={hostile} />,
        );

        expect(container.querySelector('script')).toBeNull();
        expect(container.querySelector('img')).toBeNull();
        expect(
            screen.getAllByText('<img src=x onerror="alert(1)">Опасность').length,
        ).toBeGreaterThan(0);
    });
});

describe('home page', () => {
    it('renders the public station overview and the integration status board', () => {
        render(<Home generatedAt="2026-08-31T06:05:00Z" stations={stations} alerts={alerts} />);

        expect(
            screen.getByRole('heading', { level: 1, name: translation('home_heading') }),
        ).toBeInTheDocument();
        expect(screen.getByTestId('station-map')).toBeInTheDocument();
        expect(screen.getAllByText('Тестовая станция 001').length).toBeGreaterThan(0);
        expect(screen.getAllByText('23.4 ug/m3').length).toBeGreaterThan(0);
        expect(screen.getAllByText('—').length).toBeGreaterThan(0);
        expect(screen.getByText(translation('mock_data_notice'))).toBeInTheDocument();
        expect(
            screen.getByRole('heading', {
                level: 2,
                name: translation('home_integration_status_heading'),
            }),
        ).toBeInTheDocument();
        expect(screen.getByText(translation('integration_alerts_heading'))).toBeInTheDocument();
        expect(screen.getByText(translation('integration_silam_heading'))).toBeInTheDocument();
    });

    /*
     * The page renders warnings in force and links a working SILAM page, so it
     * must not simultaneously claim either is still to be built. What it must
     * keep saying is that the warning data is synthetic.
     */
    it('does not describe a working feature as not yet implemented', () => {
        render(<Home generatedAt="2026-08-31T06:05:00Z" stations={stations} alerts={alerts} />);

        expect(screen.queryByText('Следующие интеграции')).not.toBeInTheDocument();
        expect(screen.queryByText('Ожидаются данные Гидромета.')).not.toBeInTheDocument();
        expect(screen.queryByText('Страница FMI будет встроена отдельно.')).not.toBeInTheDocument();

        expect(screen.getByText(translation('integration_alerts_body'))).toBeInTheDocument();
        expect(screen.getByText(translation('integration_silam_body'))).toBeInTheDocument();
        expect(
            screen.getByText(translation('integration_status_fixture_backed')),
        ).toBeInTheDocument();
        expect(screen.getByText(translation('integration_status_available'))).toBeInTheDocument();
        // The honest caveat has to survive the rewording.
        expect(screen.getByText(translation('alerts_mock_notice'))).toBeInTheDocument();
    });

    it('renders an honest empty state before station data is imported', () => {
        render(<Home generatedAt="2026-08-31T06:05:00Z" stations={[]} alerts={[]} />);

        expect(screen.queryByTestId('station-map')).not.toBeInTheDocument();
        expect(screen.getByText(translation('station_empty_heading'))).toBeInTheDocument();
        expect(screen.queryByText(translation('mock_data_notice'))).not.toBeInTheDocument();
    });

    it('renders the language switcher with every application locale', () => {
        render(<Home generatedAt="2026-08-31T06:05:00Z" stations={stations} alerts={alerts} />);

        expect(
            screen.getByRole('button', { name: translation('language_label') }),
        ).toBeInTheDocument();
    });

    it('renders timestamps in the display timezone', () => {
        render(<Home generatedAt="2026-08-31T06:05:00Z" stations={stations} alerts={alerts} />);

        // Selected by its own timestamp: the warning cards render <time>
        // elements too, so "the only <time> on the page" is no longer true.
        const generated = screen
            .getAllByText((_, element) => element?.tagName === 'TIME')
            .find((element) => element.getAttribute('datetime') === '2026-08-31T06:05:00Z');

        // 06:05 UTC is 11:05 in Asia/Dushanbe (UTC+5, no daylight saving).
        expect(generated).toBeDefined();
        expect(generated).toHaveTextContent('11:05');
        expect(screen.getByText(/11:00/)).toBeInTheDocument();
    });
});
