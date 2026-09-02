import { useEffect } from 'react';

import { usePortal } from '@/hooks/use-portal';

/**
 * Keeps `<html lang>` in step with the active locale.
 *
 * Blade renders the correct tag on first load, but an Inertia visit replaces
 * the page component without touching `<html>`, so switching to Tajik used to
 * leave the document still declaring English. That is not cosmetic: the `lang`
 * attribute is what a screen reader picks its pronunciation from, and what a
 * browser uses to choose hyphenation and offer translation.
 *
 * The tag comes from `locale.bcp47`, the same server value Blade rendered, so
 * the first load and every later switch cannot disagree. The internal
 * application key stays `tj`; the standards-based tag is a boundary concern
 * and is mapped server-side in `App\Support\Locale\SupportedLocale`.
 *
 * Called once, from the shared public layout, rather than page by page.
 */
export function useDocumentLanguage(): void {
    const { locale } = usePortal();

    useEffect(() => {
        if (typeof document === 'undefined') {
            return;
        }

        if (document.documentElement.lang !== locale.bcp47) {
            document.documentElement.lang = locale.bcp47;
        }
    }, [locale.bcp47]);
}
