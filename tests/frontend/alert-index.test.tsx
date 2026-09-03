import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import AlertIndex from '@/pages/alerts/index';
import type { PublicAlertHistoryRow } from '@/types';
import { sharedProps } from './shared-props';

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <span data-testid="head-title">{title}</span>,
    Link: ({ href, children, ...props }: React.ComponentProps<'a'>) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    usePage: () => ({ props: sharedProps }),
}));

function row(overrides: Partial<PublicAlertHistoryRow> = {}): PublicAlertHistoryRow {
    return {
        identifier: 'TJ-ALERT-1',
        source: 'fixture',
        isMock: false,
        severity: 'Severe',
        messageType: 'Alert',
        headline: 'Сильный дождь',
        sentAt: '2026-05-01T00:00:00Z',
        effectiveAt: '2026-05-01T06:00:00Z',
        expiresAt: '2026-05-02T00:00:00Z',
        supersededAt: null,
        isActive: true,
        areas: ['Тестовый район'],
        ...overrides,
    };
}

const translation = (key: string) => sharedProps.translations[key] ?? key;

describe('AlertIndex', () => {
    it('lists each warning with a link to its own page', () => {
        render(<AlertIndex alerts={[row()]} older={null} newer={null} />);

        expect(screen.getByRole('link', { name: 'Сильный дождь' })).toHaveAttribute(
            'href',
            '/alerts/fixture/TJ-ALERT-1',
        );
        expect(screen.getByText('Тестовый район')).toBeInTheDocument();
    });

    /**
     * The reason the page exists: one list holds warnings that are in force,
     * ones that ran out and ones that were withdrawn, and they must not look
     * alike.
     */
    it('distinguishes in force, expired and withdrawn', () => {
        render(
            <AlertIndex
                alerts={[
                    // Headlines deliberately unlike the state labels, so an
                    // assertion cannot match the wrong element.
                    row({ identifier: 'A', headline: 'Первое сообщение', isActive: true }),
                    row({ identifier: 'B', headline: 'Второе сообщение', isActive: false }),
                    row({
                        identifier: 'C',
                        headline: 'Третье сообщение',
                        isActive: false,
                        supersededAt: '2026-05-01T12:00:00Z',
                    }),
                ]}
                older={null}
                newer={null}
            />,
        );

        const entry = (headline: string) =>
            screen.getByRole('link', { name: headline }).closest('li') as HTMLElement;

        expect(
            within(entry('Первое сообщение')).getByText(translation('alert_state_in_force')),
        ).toBeInTheDocument();
        expect(
            within(entry('Второе сообщение')).getByText(translation('alert_state_ended')),
        ).toBeInTheDocument();
        expect(
            within(entry('Третье сообщение')).getByText(translation('alert_state_withdrawn')),
        ).toBeInTheDocument();
    });

    it('shows the message type so an update is not mistaken for a new warning', () => {
        render(<AlertIndex alerts={[row({ messageType: 'Cancel' })]} older={null} newer={null} />);

        expect(screen.getByText(translation('alert_message_type_cancel'))).toBeInTheDocument();
    });

    it('renders times in the portal display timezone', () => {
        render(<AlertIndex alerts={[row()]} older={null} newer={null} />);

        // 06:00 UTC is 11:00 in Asia/Dushanbe.
        expect(screen.getByText('1 мая 2026 г., 11:00')).toBeInTheDocument();
    });

    it('keeps demonstration data labelled', () => {
        render(<AlertIndex alerts={[row({ isMock: true })]} older={null} newer={null} />);

        expect(screen.getByText(translation('mock_data_badge'))).toBeInTheDocument();
    });

    it('says plainly when nothing has been published', () => {
        render(<AlertIndex alerts={[]} older={null} newer={null} />);

        expect(screen.getByText(translation('alert_history_empty'))).toBeInTheDocument();
    });

    /**
     * Paging is plain links, so it works by keyboard and without JavaScript.
     */
    it('offers paging in both directions when there is more to read', () => {
        render(<AlertIndex alerts={[row()]} older="older-cursor" newer="newer-cursor" />);

        expect(
            screen.getByRole('link', { name: translation('alert_history_older') }),
        ).toHaveAttribute('href', '/alerts?cursor=older-cursor');
        expect(
            screen.getByRole('link', { name: translation('alert_history_newer') }),
        ).toHaveAttribute('href', '/alerts?cursor=newer-cursor');
    });

    it('offers no paging when the whole history fits on one page', () => {
        render(<AlertIndex alerts={[row()]} older={null} newer={null} />);

        expect(screen.queryByRole('link', { name: translation('alert_history_older') })).toBeNull();
        expect(screen.queryByRole('link', { name: translation('alert_history_newer') })).toBeNull();
    });

    it('escapes a cursor before putting it in a URL', () => {
        render(<AlertIndex alerts={[row()]} older="a b&c=d" newer={null} />);

        expect(
            screen.getByRole('link', { name: translation('alert_history_older') }),
        ).toHaveAttribute('href', '/alerts?cursor=a%20b%26c%3Dd');
    });

    it('offers a way back to the overview', () => {
        render(<AlertIndex alerts={[row()]} older={null} newer={null} />);

        expect(screen.getByRole('link', { name: translation('alert_back') })).toHaveAttribute(
            'href',
            '/',
        );
    });
});
