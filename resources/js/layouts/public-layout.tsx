import { Link } from '@inertiajs/react';
import { Wind } from 'lucide-react';
import type { PropsWithChildren } from 'react';

import { LanguageSwitcher } from '@/components/language-switcher';
import { useDocumentLanguage } from '@/hooks/use-document-language';
import { useTranslations } from '@/hooks/use-portal';

export function PublicLayout({ children }: PropsWithChildren) {
    const t = useTranslations();

    // Every public page renders through this layout, so the document language
    // is corrected in one place instead of once per page.
    useDocumentLanguage();

    return (
        <div className="flex min-h-screen flex-col">
            <header className="border-b">
                <div className="mx-auto flex w-full max-w-5xl items-center justify-between gap-4 px-4 py-4">
                    <Link href="/" className="min-w-0">
                        <p className="truncate font-heading text-base font-semibold">
                            {t('brand_name')}
                        </p>
                        <p className="truncate text-sm text-muted-foreground">
                            {t('brand_tagline')}
                        </p>
                    </Link>
                    <div className="flex items-center gap-2">
                        <nav className="flex items-center" aria-label={t('main_navigation')}>
                            <Link
                                className="flex items-center gap-2 rounded-lg p-2 text-sm hover:bg-muted md:px-3"
                                href="/silam"
                                aria-label={t('nav_silam')}
                            >
                                <Wind className="size-4" aria-hidden />
                                <span className="hidden md:inline">{t('nav_silam')}</span>
                            </Link>
                        </nav>
                        <LanguageSwitcher />
                    </div>
                </div>
            </header>

            <main className="mx-auto w-full max-w-5xl flex-1 px-4 py-10">{children}</main>

            <footer className="border-t">
                <div className="mx-auto w-full max-w-5xl space-y-1 px-4 py-6 text-sm text-muted-foreground">
                    <p>{t('footer_source')}</p>
                    <p>{t('footer_note')}</p>
                </div>
            </footer>
        </div>
    );
}
