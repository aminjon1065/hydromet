import type { LocaleKey } from '@/types';

/**
 * BCP 47 tags used for `Intl` formatting. The internal `tj` key is mapped to
 * the standards-based tag only here, at the formatting boundary.
 */
const INTL_LOCALES: Record<LocaleKey, string> = {
    tj: 'tg-TJ',
    ru: 'ru-RU',
    en: 'en-GB',
};

/**
 * Format a UTC ISO timestamp in the portal's display timezone.
 */
export function formatDateTime(isoUtc: string, locale: LocaleKey, timeZone: string): string {
    const date = new Date(isoUtc);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return new Intl.DateTimeFormat(INTL_LOCALES[locale], {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone,
    }).format(date);
}
