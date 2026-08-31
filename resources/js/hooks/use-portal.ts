import { usePage } from '@inertiajs/react';

import type { SharedProps } from '@/types';

/**
 * Shared server state: active locale, display timezone and the translated
 * strings for the active locale.
 */
export function usePortal(): SharedProps {
    return usePage<SharedProps>().props;
}

/**
 * Translate a key from `lang/{locale}/site.php`.
 *
 * A missing key returns the key itself so an untranslated string is visible
 * during review instead of rendering as an empty element.
 */
export function useTranslations(): (key: string, replacements?: Record<string, string>) => string {
    const { translations } = usePortal();

    return (key, replacements = {}) => {
        const line = translations[key] ?? key;

        return Object.entries(replacements).reduce(
            (carry, [placeholder, value]) => carry.replaceAll(`:${placeholder}`, value),
            line,
        );
    };
}
