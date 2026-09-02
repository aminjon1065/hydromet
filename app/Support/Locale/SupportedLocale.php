<?php

namespace App\Support\Locale;

use Illuminate\Support\Facades\App;

/**
 * Application locale keys.
 *
 * The portal uses `tj`, `ru` and `en` internally (docs/03-data-contracts.md).
 * The internal `tj` key is mapped to the standards-based BCP 47 tag `tg-TJ`
 * only at external boundaries: HTML metadata, CAP messages and other
 * standards-based protocols. Never store or compare the BCP 47 tag internally.
 */
enum SupportedLocale: string
{
    case Tajik = 'tj';
    case Russian = 'ru';
    case English = 'en';

    /**
     * BCP 47 / ISO tag used for HTML `lang`, `Content-Language` and CAP.
     *
     * Region-qualified for every language, not only Tajik, so the tag the
     * server renders into `<html lang>` on first load is the same tag the
     * client re-applies after an Inertia language switch. Two spellings of the
     * same language would make the two paths disagree, which is precisely the
     * defect this single source of truth exists to prevent.
     */
    public function bcp47(): string
    {
        return match ($this) {
            self::Tajik => 'tg-TJ',
            self::Russian => 'ru-RU',
            self::English => 'en-GB',
        };
    }

    /**
     * Endonym shown in the language switcher. Deliberately untranslated:
     * a language name is always written in its own language.
     */
    public function nativeName(): string
    {
        return match ($this) {
            self::Tajik => 'Тоҷикӣ',
            self::Russian => 'Русский',
            self::English => 'English',
        };
    }

    public static function fallback(): self
    {
        return self::tryFrom((string) config('app.fallback_locale')) ?? self::Russian;
    }

    public static function current(): self
    {
        return self::tryFrom(App::getLocale()) ?? self::fallback();
    }

    /**
     * Resolve an application locale from an internal key or an external
     * standards-based tag such as `tg`, `tg-TJ`, `ru-RU` or `en-GB`.
     */
    public static function resolve(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = strtolower(str_replace('_', '-', trim($value)));

        if (str_starts_with($normalized, 'tg')) {
            return self::Tajik;
        }

        $primary = explode('-', $normalized)[0];

        return self::tryFrom($primary);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
