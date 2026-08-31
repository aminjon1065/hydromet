import { render, screen } from '@testing-library/react';
import type { AnchorHTMLAttributes, PropsWithChildren } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { sharedProps } from './shared-props';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...props }: PropsWithChildren<AnchorHTMLAttributes<HTMLAnchorElement>>) => (
        <a {...props}>{children}</a>
    ),
    usePage: () => ({ props: sharedProps }),
}));

const { default: Home } = await import('@/pages/home');

describe('home page', () => {
    it('renders the translated heading and the roadmap sections', () => {
        render(<Home generatedAt="2026-08-31T06:05:00Z" />);

        expect(
            screen.getByRole('heading', { level: 1, name: sharedProps.translations.home_heading }),
        ).toBeInTheDocument();

        for (const key of ['roadmap_map', 'roadmap_charts', 'roadmap_alerts', 'roadmap_silam']) {
            expect(screen.getByText(sharedProps.translations[key] as string)).toBeInTheDocument();
        }
    });

    it('renders the language switcher with every application locale', () => {
        render(<Home generatedAt="2026-08-31T06:05:00Z" />);

        expect(
            screen.getByRole('button', { name: sharedProps.translations.language_label }),
        ).toBeInTheDocument();
    });

    it('renders the generated timestamp in the display timezone', () => {
        render(<Home generatedAt="2026-08-31T06:05:00Z" />);

        const time = screen.getByText((_, element) => element?.tagName === 'TIME');

        // 06:05 UTC is 11:05 in Asia/Dushanbe (UTC+5, no daylight saving).
        expect(time).toHaveTextContent('11:05');
        expect(time).toHaveAttribute('dateTime', '2026-08-31T06:05:00Z');
    });
});
