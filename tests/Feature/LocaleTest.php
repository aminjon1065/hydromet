<?php

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
use App\Support\Locale\SupportedLocale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    #[Test]
    public function the_fallback_locale_is_russian(): void
    {
        $this->assertSame('ru', config('app.fallback_locale'));
        $this->assertSame(SupportedLocale::Russian, SupportedLocale::fallback());
    }

    #[Test]
    public function a_request_without_an_acceptable_language_falls_back_to_russian(): void
    {
        $this->withHeader('Accept-Language', '')->get('/')->assertOk();

        $this->assertSame('ru', app()->getLocale());
    }

    #[Test]
    #[DataProvider('acceptLanguageHeaders')]
    public function the_accept_language_header_selects_an_application_locale(string $header, string $expected): void
    {
        $this->withHeader('Accept-Language', $header)->get('/')->assertOk();

        $this->assertSame($expected, app()->getLocale());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function acceptLanguageHeaders(): array
    {
        return [
            'russian' => ['ru-RU,ru;q=0.9', 'ru'],
            'english' => ['en-GB,en;q=0.9', 'en'],
            'standards based tajik' => ['tg-TJ,tg;q=0.9', 'tj'],
            'bare tajik tag' => ['tg', 'tj'],
            'unsupported language' => ['de-DE,de;q=0.9', 'ru'],
        ];
    }

    #[Test]
    public function an_explicit_choice_is_remembered_and_wins_over_the_header(): void
    {
        $this->get('/language/en')->assertRedirect();

        $this->assertSame('en', session(SetLocale::SESSION_KEY));

        $this->withHeader('Accept-Language', 'ru-RU')->get('/')->assertOk();

        $this->assertSame('en', app()->getLocale());
    }

    #[Test]
    public function an_unsupported_locale_in_the_url_falls_back_instead_of_being_stored(): void
    {
        $this->get('/language/de')->assertRedirect();

        $this->assertSame('ru', session(SetLocale::SESSION_KEY));
    }

    #[Test]
    public function responses_advertise_the_standards_based_content_language(): void
    {
        $this->withSession([SetLocale::SESSION_KEY => 'tj'])
            ->get('/')
            ->assertOk()
            ->assertHeader('Content-Language', 'tg-TJ');
    }
}
