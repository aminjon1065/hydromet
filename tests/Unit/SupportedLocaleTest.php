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

    #[Test]
    public function the_internal_tajik_key_is_mapped_to_a_standards_based_tag(): void
    {
        $this->assertSame('tg-TJ', SupportedLocale::Tajik->bcp47());
        $this->assertSame('ru', SupportedLocale::Russian->bcp47());
        $this->assertSame('en', SupportedLocale::English->bcp47());
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
