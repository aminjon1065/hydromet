<?php

namespace Tests\Unit;

use App\Support\Locale\SupportedLocale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SupportedLocaleTest extends TestCase
{
    #[Test]
    public function the_application_uses_exactly_three_locale_keys(): void
    {
        $this->assertSame(['tj', 'ru', 'en'], SupportedLocale::values());
    }

    /**
     * Every locale gets a region-qualified tag, not only Tajik: the server
     * renders this into `<html lang>` and the client re-applies the same value
     * after a language switch, so a second spelling would make the two paths
     * disagree on the same language.
     */
    #[Test]
    public function the_internal_keys_are_mapped_to_standards_based_tags(): void
    {
        $this->assertSame('tg-TJ', SupportedLocale::Tajik->bcp47());
        $this->assertSame('ru-RU', SupportedLocale::Russian->bcp47());
        $this->assertSame('en-GB', SupportedLocale::English->bcp47());
    }

    /**
     * The mapping is one-way. An external tag still resolves back to the
     * internal key, which stays `tj` everywhere inside the application.
     */
    #[Test]
    public function a_standards_based_tag_resolves_back_to_its_internal_key(): void
    {
        foreach (SupportedLocale::cases() as $locale) {
            $this->assertSame($locale, SupportedLocale::resolve($locale->bcp47()));
        }

        $this->assertSame('tj', SupportedLocale::Tajik->value);
    }

    #[Test]
    #[DataProvider('externalTags')]
    public function external_tags_resolve_to_application_locale_keys(?string $tag, ?SupportedLocale $expected): void
    {
        $this->assertSame($expected, SupportedLocale::resolve($tag));
    }

    /**
     * @return array<string, array{string|null, SupportedLocale|null}>
     */
    public static function externalTags(): array
    {
        return [
            'internal key' => ['tj', SupportedLocale::Tajik],
            'iso 639 tajik' => ['tg', SupportedLocale::Tajik],
            'bcp 47 tajik' => ['tg-TJ', SupportedLocale::Tajik],
            'underscored tajik' => ['tg_TJ', SupportedLocale::Tajik],
            'regional russian' => ['ru-RU', SupportedLocale::Russian],
            'regional english' => ['en-GB', SupportedLocale::English],
            'unsupported' => ['de-DE', null],
            'empty' => ['', null],
            'null' => [null, null],
        ];
    }
}
