import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import AlertShow from '@/pages/alerts/show';
import type { PublicAlertDetail, PublicAlertHistoryEntry } from '@/types';
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

function alert(overrides: Partial<PublicAlertDetail> = {}): PublicAlertDetail {
    return {
        identifier: 'TJ-ALERT-1',
        source: 'fixture',
        isMock: false,
        eventCode: 'TEST_EVENT',
        severity: 'Severe',
        urgency: 'Expected',
        certainty: 'Likely',
        sender: 'test-warning-desk',
        headline: 'Сильный дождь',
        description: 'Продолжительные осадки в долине.',
        instruction: 'Держитесь подальше от берега.',
        sentAt: '2026-05-30T04:00:00Z',
        effectiveAt: '2026-05-30T06:00:00Z',
        onsetAt: null,
        expiresAt: '2026-06-30T18:00:00Z',
        areas: [{ description: 'Тестовый район', geometry: null, geocodes: [] }],
        status: 'Actual',
        messageType: 'Alert',
        supersededAt: null,
        isActive: true,
        ...overrides,
    };
}

/** The message being read, as it appears in its own chain. */
const self: PublicAlertHistoryEntry = {
    identifier: 'TJ-ALERT-1',
    messageType: 'Alert',
    severity: 'Severe',
    headline: 'Сильный дождь',
    sentAt: '2026-05-01T00:00:00Z',
    supersededAt: '2026-05-03T00:00:00Z',
};

/** The message that replaced it. Its headline is deliberately not the word the
 *  message-type badge uses, so the two cannot be confused in an assertion. */
const cancellation: PublicAlertHistoryEntry = {
    identifier: 'CHAIN-CANCEL',
    messageType: 'Cancel',
    severity: 'Severe',
    headline: 'Дождь прекратился',
    sentAt: '2026-05-03T00:00:00Z',
    supersededAt: null,
};

const alone: PublicAlertHistoryEntry[] = [self];
const chain: PublicAlertHistoryEntry[] = [cancellation, self];

describe('AlertShow', () => {
    it('renders the warning, its instruction and its facts', () => {
        render(<AlertShow alert={alert()} history={alone} />);

        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Сильный дождь');
        expect(screen.getByText('Продолжительные осадки в долине.')).toBeInTheDocument();
        expect(screen.getByText('Держитесь подальше от берега.')).toBeInTheDocument();
        expect(screen.getByText('Тестовый район')).toBeInTheDocument();
        expect(screen.getByText('test-warning-desk')).toBeInTheDocument();
    });

    it('translates the CAP vocabulary instead of printing it raw', () => {
        render(<AlertShow alert={alert()} history={alone} />);

        expect(screen.getByText('Ожидаемая')).toBeInTheDocument();
        expect(screen.getByText('Вероятно')).toBeInTheDocument();
        expect(screen.getByText('Высокий')).toBeInTheDocument();
        // The raw CAP tokens must not reach the page.
        expect(screen.queryByText('Expected')).not.toBeInTheDocument();
        expect(screen.queryByText('Likely')).not.toBeInTheDocument();
    });

    it('renders times in the portal display timezone', () => {
        render(<AlertShow alert={alert()} history={alone} />);

        // 06:00 UTC is 11:00 in Asia/Dushanbe.
        expect(screen.getByText('30 мая 2026 г., 11:00')).toBeInTheDocument();
    });

    it('says nothing about currency while the warning is in force', () => {
        render(<AlertShow alert={alert()} history={alone} />);

        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    it('announces an expired warning instead of hiding it', () => {
        render(<AlertShow alert={alert({ isActive: false })} history={alone} />);

        const state = screen.getByRole('status');

        expect(state).toHaveTextContent('больше не действует');
        // The interpolated moment, in the display timezone.
        expect(state).toHaveTextContent('30 июн. 2026 г., 23:00');
    });

    it('announces a superseded warning with the moment it was replaced', () => {
        render(
            <AlertShow
                alert={alert({ isActive: false, supersededAt: '2026-05-20T09:00:00Z' })}
                history={chain}
            />,
        );

        const state = screen.getByRole('status');

        expect(state).toHaveTextContent('больше не является текущим');
        expect(state).toHaveTextContent('20 мая 2026 г., 14:00');
    });

    it('links every other message in the chain and marks the current one', () => {
        render(<AlertShow alert={alert()} history={chain} />);

        const link = screen.getByRole('link', { name: 'Дождь прекратился' });

        expect(link).toHaveAttribute('href', '/alerts/fixture/CHAIN-CANCEL');
        // The entry carries its own message type, so a reader can tell an
        // update from a cancellation without opening it.
        expect(link.closest('li')).toHaveTextContent('Отмена');

        // The message being read is present but is not a link to itself, and is
        // marked as the one you are on.
        expect(screen.queryByRole('link', { name: 'Сильный дождь' })).toBeNull();
        expect(
            screen.getByText('Сильный дождь', { selector: '[aria-current="true"]' }),
        ).toBeInTheDocument();
    });

    it('omits the history when this message is the only one', () => {
        render(<AlertShow alert={alert()} history={alone} />);

        expect(screen.queryByText('История сообщений')).not.toBeInTheDocument();
    });

    it('offers a way back to the overview', () => {
        render(<AlertShow alert={alert()} history={alone} />);

        expect(screen.getByRole('link', { name: 'Назад к обзору' })).toHaveAttribute('href', '/');
    });

    it('keeps demonstration data labelled as demonstration data', () => {
        render(<AlertShow alert={alert({ isMock: true })} history={alone} />);

        expect(screen.getByText('Искусственные данные')).toBeInTheDocument();
    });

    it('states plainly when no area was given rather than showing an empty field', () => {
        render(<AlertShow alert={alert({ areas: [] })} history={alone} />);

        expect(screen.getByText('Не указано')).toBeInTheDocument();
    });
});
