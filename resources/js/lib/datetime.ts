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
 * CLDR's abbreviated month names for `tg`, copied from ICU rather than
 * translated here.
 *
 * They are only ever used by {@link formatTajik}, and only on a runtime that
 * has no Tajik data of its own.
 */
const TAJIK_SHORT_MONTHS = [
    'Янв',
    'Фев',
    'Мар',
    'Апр',
    'Май',
    'Июн',
    'Июл',
    'Авг',
    'Сен',
    'Окт',
    'Ноя',
    'Дек',
];

/**
 * Whether this runtime can actually format Tajik.
 *
 * Asked by constructing a formatter and reading back the locale it settled on,
 * rather than through `supportedLocalesOf`: what matters is not whether the tag
 * is recognised but whether the output will be Tajik. Chrome answers `en-US`
 * here; Node answers `tg-TJ`.
 *
 * Decided once, when the module loads. The answer is a property of the runtime
 * and cannot change while the page is open, so asking again per timestamp would
 * buy nothing.
 */
const RUNTIME_FORMATS_TAJIK: boolean = ((): boolean => {
    try {
        return new Intl.DateTimeFormat(INTL_LOCALES.tj)
            .resolvedOptions()
            .locale.toLowerCase()
            .startsWith('tg');
    } catch {
        return false;
    }
})();

/**
 * CLDR's `tg-TJ` medium date and short time, composed by hand.
 *
 * Chrome ships no Tajik locale data, so `Intl` silently falls back to `en-US`
 * and every timestamp on a Tajik page reads `Jan 15, 2026, 11:30 AM` — English
 * text on the pages of the state language. Tajik is not a formatting
 * preference here; it is the language the page is written in.
 *
 * Only the words are supplied locally. The calendar arithmetic and the timezone
 * conversion — the parts that are genuinely hard and that a hand-rolled
 * implementation gets wrong — still come from `Intl`, asked in a locale every
 * runtime has. What is assembled is exactly CLDR's pattern for `tg`:
 * `02 Янв 2026, 10:00`, verified against ICU's own output rather than against a
 * format written down from memory (`tests/frontend/datetime.test.ts`).
 *
 * This is deliberately not a general "unsupported locale" mechanism. Russian
 * and English are available in every runtime the portal supports, so Tajik is
 * the only case, and a general one would be a guess about locales nobody has
 * asked for.
 */
function formatTajik(date: Date, timeZone: string): string {
    const parts = new Intl.DateTimeFormat('en-GB', {
        day: '2-digit',
        month: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        // `h23` explicitly: `hour12: false` is allowed to mean `h24`, which
        // renders midnight as 24:00.
        hourCycle: 'h23',
        timeZone,
    }).formatToParts(date);

    const value = (type: Intl.DateTimeFormatPartTypes): string =>
        parts.find((part) => part.type === type)?.value ?? '';

    const month = TAJIK_SHORT_MONTHS[Number(value('month')) - 1];

    if (month === undefined) {
        // Unreachable for a valid date; falling back to the numeric month keeps
        // a readable timestamp rather than an empty one.
        return `${value('day')}.${value('month')}.${value('year')}, ${value('hour')}:${value('minute')}`;
    }

    return `${value('day')} ${month} ${value('year')}, ${value('hour')}:${value('minute')}`;
}

/**
 * Format a UTC ISO timestamp in the portal's display timezone.
 */
export function formatDateTime(isoUtc: string, locale: LocaleKey, timeZone: string): string {
    const date = new Date(isoUtc);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    // The fallback retires itself: a runtime that gains Tajik data uses it, and
    // this branch stops being taken without anyone editing the portal.
    if (locale === 'tj' && !RUNTIME_FORMATS_TAJIK) {
        return formatTajik(date, timeZone);
    }

    return new Intl.DateTimeFormat(INTL_LOCALES[locale], {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone,
    }).format(date);
}
