import { afterEach, describe, expect, it, vi } from 'vitest';

const TIMEZONE = 'Asia/Dushanbe';

/**
 * The portal's date formatting, and specifically the Tajik fallback.
 *
 * Chrome ships no `tg` CLDR data — `new Intl.DateTimeFormat('tg-TJ')` resolves
 * to `en-US` there — so every timestamp on a Tajik page rendered as
 * `Jan 15, 2026, 11:30 AM`. Node's ICU *does* have `tg`, which is why the
 * component tests never caught it: they run on Node and on the `ru` locale.
 *
 * That asymmetry is what makes these assertions possible. Node can produce the
 * authoritative answer, so the fallback is never compared against a format
 * written down here — it is compared against ICU's own `tg-TJ` output, instant
 * by instant. If the two ever disagree this fails, and nothing in this file has
 * to be maintained as a copy of CLDR.
 *
 * The module decides once, at load, whether the runtime can format Tajik, so
 * each test loads it fresh against the runtime it wants to describe.
 */
describe('formatDateTime', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        vi.resetModules();
    });

    /**
     * What ICU itself renders, on a runtime that has Tajik data.
     */
    function icuTajik(isoUtc: string): string {
        return new Intl.DateTimeFormat('tg-TJ', {
            dateStyle: 'medium',
            timeStyle: 'short',
            timeZone: TIMEZONE,
        }).format(new Date(isoUtc));
    }

    /**
     * Load the module against a chosen runtime, and record every locale it asks
     * `Intl` for.
     *
     * `tajik: false` makes the runtime look like Chrome — a `tg` tag resolves to
     * `en-US` — which is the condition the fallback exists for. Every other
     * locale keeps formatting exactly as it did.
     *
     * The stub delegates to the real constructor rather than replacing it, so
     * the formatters it hands back are genuine and the recorded locales are the
     * ones the portal actually requested.
     */
    async function loadFormatter(runtime: { tajik: boolean }) {
        vi.resetModules();

        const native = Intl.DateTimeFormat;
        const locales: string[] = [];

        vi.stubGlobal('Intl', {
            ...Intl,
            DateTimeFormat: function (locale?: string, options?: Intl.DateTimeFormatOptions) {
                const asked = typeof locale === 'string' ? locale : '';
                locales.push(asked);

                const tajik = asked.toLowerCase().startsWith('tg');

                return new native(!runtime.tajik && tajik ? 'en-US' : locale, options);
            } as unknown as typeof Intl.DateTimeFormat,
        });

        const { formatDateTime } = await import('@/lib/datetime');

        // Drop the module's one load-time capability probe, so what remains is
        // what formatting asked for.
        locales.length = 0;

        return { formatDateTime, locales };
    }

    it('confirms this runtime has the Tajik data the browser lacks', () => {
        // The premise of every comparison below. If Node ever loses `tg`, they
        // would silently start comparing English with English.
        expect(new Intl.DateTimeFormat('tg-TJ').resolvedOptions().locale).toMatch(/^tg/);
    });

    /**
     * Chosen for the cases a hand-written formatter gets wrong: a single-digit
     * day, midnight, the last minute of a day, and an instant whose UTC date and
     * Dushanbe date differ — 19:00 UTC on 31 December is already the new year in
     * Dushanbe.
     */
    const instants = [
        '2026-01-02T05:00:00Z',
        '2026-06-15T18:05:00Z',
        '2026-12-31T19:00:00Z',
        '2026-07-01T18:59:00Z',
        '2026-03-09T00:00:00Z',
        '2026-11-30T23:59:00Z',
    ];

    it.each(instants)('falls back to exactly what ICU renders for tg-TJ (%s)', async (isoUtc) => {
        const expected = icuTajik(isoUtc);
        const { formatDateTime } = await loadFormatter({ tajik: false });

        expect(formatDateTime(isoUtc, 'tj', TIMEZONE)).toBe(expected);
    });

    it('renders Tajik text rather than the English a browser would produce', async () => {
        const { formatDateTime } = await loadFormatter({ tajik: false });

        const formatted = formatDateTime('2026-01-15T06:30:00Z', 'tj', TIMEZONE);

        // The defect this fixes, stated as an assertion.
        expect(formatted).not.toMatch(/Jan|AM|PM/);
        expect(formatted).toBe('15 Янв 2026, 11:30');
    });

    /**
     * The fallback is meant to retire itself, so which path runs has to be
     * observable — the two produce the same string by design, and comparing
     * output alone would prove nothing. The locale the formatter is built with
     * is the difference: `tg-TJ` when the runtime can do it, `en-GB` when the
     * portal is assembling the parts itself.
     */
    it('asks the runtime for Tajik directly when it has the data', async () => {
        const { formatDateTime, locales } = await loadFormatter({ tajik: true });

        formatDateTime('2026-01-15T06:30:00Z', 'tj', TIMEZONE);

        expect(locales).toContain('tg-TJ');
    });

    it('never asks for a Tajik formatter on a runtime that cannot honour it', async () => {
        const { formatDateTime, locales } = await loadFormatter({ tajik: false });

        formatDateTime('2026-01-15T06:30:00Z', 'tj', TIMEZONE);

        // Asking for `tg-TJ` there would silently produce US English, which is
        // the defect. The parts are gathered in a locale every runtime has.
        expect(locales).not.toContain('tg-TJ');
        expect(locales).toContain('en-GB');
    });

    it('leaves the other locales to Intl', async () => {
        const { formatDateTime } = await loadFormatter({ tajik: false });

        expect(formatDateTime('2026-01-15T06:30:00Z', 'ru', TIMEZONE)).toBe(
            '15 янв. 2026 г., 11:30',
        );
        expect(formatDateTime('2026-01-15T06:30:00Z', 'en', TIMEZONE)).toBe('15 Jan 2026, 11:30');
    });

    it('honours the display timezone on the fallback path too', async () => {
        const { formatDateTime } = await loadFormatter({ tajik: false });

        // 06:30 UTC is 11:30 in Dushanbe and 06:30 in UTC.
        expect(formatDateTime('2026-01-15T06:30:00Z', 'tj', TIMEZONE)).toBe('15 Янв 2026, 11:30');
        expect(formatDateTime('2026-01-15T06:30:00Z', 'tj', 'UTC')).toBe('15 Янв 2026, 06:30');
    });

    it.each(['tj', 'ru', 'en'] as const)(
        'returns nothing for an unusable value (%s)',
        async (locale) => {
            const { formatDateTime } = await loadFormatter({ tajik: false });

            expect(formatDateTime('not a timestamp', locale, TIMEZONE)).toBe('');
        },
    );
});
