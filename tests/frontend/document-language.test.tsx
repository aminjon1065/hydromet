import { render } from '@testing-library/react';
import type { AnchorHTMLAttributes, PropsWithChildren } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { LocaleKey, SharedProps } from '@/types';

import { sharedProps } from './shared-props';

/**
 * The active locale, swapped per test. Inertia replaces the page component on
 * a language switch without reloading the document, which is exactly the case
 * where `<html lang>` used to keep the tag Blade rendered on first load.
 */
let currentProps: SharedProps = sharedProps;

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...props }: PropsWithChildren<AnchorHTMLAttributes<HTMLAnchorElement>>) => (
        <a {...props}>{children}</a>
    ),
    usePage: () => ({ props: currentProps }),
}));

const { PublicLayout } = await import('@/layouts/public-layout');

function propsFor(current: LocaleKey, bcp47: string): SharedProps {
    return { ...sharedProps, locale: { ...sharedProps.locale, current, bcp47 } };
}

describe('document language', () => {
    beforeEach(() => {
        currentProps = sharedProps;
        document.documentElement.lang = 'en-GB';
    });

    it('applies the served locale tag on first render', () => {
        currentProps = propsFor('ru', 'ru-RU');

        render(<PublicLayout>content</PublicLayout>);

        expect(document.documentElement.lang).toBe('ru-RU');
    });

    /*
     * The internal application key stays `tj`; only the document tag is the
     * standards-based one.
     */
    it('uses the standards-based Tajik tag after switching to Tajik', () => {
        currentProps = propsFor('en', 'en-GB');
        const view = render(<PublicLayout>content</PublicLayout>);

        expect(document.documentElement.lang).toBe('en-GB');

        currentProps = propsFor('tj', 'tg-TJ');
        view.rerender(<PublicLayout>content</PublicLayout>);

        expect(document.documentElement.lang).toBe('tg-TJ');
        expect(currentProps.locale.current).toBe('tj');
    });

    it('follows every switch, not only the first', () => {
        currentProps = propsFor('tj', 'tg-TJ');
        const view = render(<PublicLayout>content</PublicLayout>);

        for (const [key, tag] of [
            ['ru', 'ru-RU'],
            ['en', 'en-GB'],
            ['tj', 'tg-TJ'],
        ] as Array<[LocaleKey, string]>) {
            currentProps = propsFor(key, tag);
            view.rerender(<PublicLayout>content</PublicLayout>);

            expect(document.documentElement.lang).toBe(tag);
        }
    });
});
