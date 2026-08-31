/**
 * Application locale keys. The standards-based `tg` / `tg-TJ` tag is produced
 * on the server for HTML metadata only and never used as an internal key.
 */
export type LocaleKey = 'tj' | 'ru' | 'en';

export interface LocaleOption {
    value: LocaleKey;
    label: string;
}

export interface LocaleShare {
    current: LocaleKey;
    bcp47: string;
    fallback: LocaleKey;
    available: LocaleOption[];
}

export interface SharedProps {
    locale: LocaleShare;
    displayTimezone: string;
    translations: Record<string, string>;
    [key: string]: unknown;
}
