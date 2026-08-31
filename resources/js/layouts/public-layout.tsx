import type { PropsWithChildren } from 'react';

import { LanguageSwitcher } from '@/components/language-switcher';
import { useTranslations } from '@/hooks/use-portal';

export function PublicLayout({ children }: PropsWithChildren) {
    const t = useTranslations();

    return (
        <div className="flex min-h-screen flex-col">
            <header className="border-b">
                <div className="mx-auto flex w-full max-w-5xl items-center justify-between gap-4 px-4 py-4">
                    <div className="min-w-0">
                        <p className="truncate font-heading text-base font-semibold">
                            {t('brand_name')}
                        </p>
                        <p className="truncate text-sm text-muted-foreground">
                            {t('brand_tagline')}
                        </p>
                    </div>
                    <LanguageSwitcher />
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
