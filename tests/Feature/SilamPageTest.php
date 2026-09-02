<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SilamPageTest extends TestCase
{
    #[Test]
    public function the_silam_page_uses_the_supplied_public_fmi_url(): void
    {
        $this->get('/silam')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('silam')
                ->where('silamUrl', 'https://silam.fmi.fi/roux/TAJ/'));
    }

    #[Test]
    public function the_silam_page_allows_frames_only_from_the_fmi_origin(): void
    {
        $this->assertSame(
            "frame-src 'self' https://silam.fmi.fi",
            $this->frameDirective(),
        );
    }

    /**
     * The embed URL is operator configuration, so the policy is derived from it
     * rather than hard-coded. Otherwise changing SILAM_URL leaves a page that
     * renders an iframe its own policy blocks.
     */
    #[Test]
    public function changing_the_configured_url_moves_the_permitted_origin(): void
    {
        config(['services.silam.url' => 'https://silam.example.org/tj/']);

        $this->assertSame(
            "frame-src 'self' https://silam.example.org",
            $this->frameDirective(),
        );
    }

    /**
     * Failing closed: a misconfigured URL must not widen the policy. The page
     * still renders, with an empty frame and its working fallback link.
     */
    #[Test]
    public function a_url_that_is_not_a_usable_https_origin_keeps_frames_blocked(): void
    {
        config(['services.silam.url' => 'javascript:alert(1)']);

        $this->assertSame("frame-src 'none'", $this->frameDirective());
    }

    /**
     * The page widens exactly one directive. Everything else — the script
     * nonce above all — has to be the same policy the rest of the site gets,
     * or this route becomes the weak spot.
     */
    #[Test]
    public function the_page_keeps_the_rest_of_the_public_policy(): void
    {
        $policy = $this->policy();

        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]{40}'/", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("base-uri 'self'", $policy);
        $this->assertStringContainsString("form-action 'self'", $policy);
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);
        $this->assertStringNotContainsString("'unsafe-eval'", $policy);
    }

    private function policy(): string
    {
        $response = $this->get('/silam')->assertOk();

        return (string) $response->headers->get('Content-Security-Policy');
    }

    private function frameDirective(): string
    {
        foreach (explode('; ', $this->policy()) as $directive) {
            if (str_starts_with($directive, 'frame-src ')) {
                return $directive;
            }
        }

        return '';
    }
}
