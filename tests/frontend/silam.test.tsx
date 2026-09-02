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

const { default: Silam } = await import('@/pages/silam');

const silamUrl = 'https://silam.fmi.fi/roux/TAJ/';

describe('SILAM page', () => {
    it('embeds only the supplied page with restrictive iframe attributes', () => {
        render(<Silam silamUrl={silamUrl} />);

        const frame = screen.getByTitle('Прогноз SILAM для Таджикистана');

        expect(frame).toHaveAttribute('src', silamUrl);
        expect(frame).toHaveAttribute('referrerPolicy', 'no-referrer');
        expect(frame).toHaveAttribute(
            'sandbox',
            'allow-forms allow-popups allow-same-origin allow-scripts',
        );
        expect(frame).toHaveAttribute('loading', 'lazy');
    });

    it('provides a safe external fallback that does not control the portal tab', () => {
        render(<Silam silamUrl={silamUrl} />);

        const fallback = screen.getByRole('link', {
            name: 'Открыть SILAM в новой вкладке',
        });

        expect(fallback).toHaveAttribute('href', silamUrl);
        expect(fallback).toHaveAttribute('target', '_blank');
        expect(fallback).toHaveAttribute('rel', 'noreferrer');
    });
});
