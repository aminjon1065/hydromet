import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import ContentShow from '@/pages/content/show';
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

describe('ContentShow', () => {
    it('renders localized plain text and publication metadata', () => {
        render(
            <ContentShow
                content={{
                    slug: 'test-bulletin',
                    type: 'bulletin',
                    title: 'Тестовый бюллетень',
                    summary: 'Краткий тестовый текст.',
                    body: 'Первая строка.\nВторая строка.',
                    publishedAt: '2026-08-31T06:00:00Z',
                }}
            />,
        );

        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Тестовый бюллетень');
        expect(screen.getByText('Краткий тестовый текст.')).toBeInTheDocument();
        expect(screen.getByText(/Первая строка./)).toHaveTextContent(
            'Первая строка. Вторая строка.',
        );
        expect(screen.getByRole('link', { name: 'Назад к станциям' })).toHaveAttribute('href', '/');
        expect(screen.getByText('31 авг. 2026 г., 11:00')).toBeInTheDocument();
    });
});
