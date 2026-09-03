<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Http\Security\ContentSecurityPolicy;
use App\Http\Security\EmbedOrigin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Vite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private const BASELINE = "base-uri 'self'; connect-src 'self'; default-src 'self'; "
        ."font-src 'self'; form-action 'self'; frame-ancestors 'self'; frame-src 'none'; "
        ."img-src 'self' data: https://*.tile.openstreetmap.org; object-src 'none'";

    /**
     * @return array<string, array{string, string}>
     */
    public static function requiredHeaders(): array
    {
        return [
            'nosniff' => ['X-Content-Type-Options', 'nosniff'],
            'legacy framing' => ['X-Frame-Options', 'SAMEORIGIN'],
            'referrer' => ['Referrer-Policy', 'strict-origin-when-cross-origin'],
            'cross domain policies' => ['X-Permitted-Cross-Domain-Policies', 'none'],
            'popup isolation' => ['Cross-Origin-Opener-Policy', 'same-origin'],
            'resource embedding' => ['Cross-Origin-Resource-Policy', 'same-origin'],
        ];
    }

    // --- Powerful browser features ---------------------------------------

    /**
     * The portal uses none of these, so every one is denied outright.
     *
     * An empty allowlist — `camera=()` — refuses the feature to the page and to
     * anything it frames, including the SILAM embed. That matters more than it
     * looks: without the header, an injected script or a framed third party can
     * prompt a visitor for their location or their camera under this portal's
     * name, and an official government site is exactly where such a prompt
     * would be believed.
     *
     * @return array<string, array{string}>
     */
    public static function deniedFeatures(): array
    {
        return [
            'camera' => ['camera'],
            'microphone' => ['microphone'],
            'geolocation' => ['geolocation'],
            'display capture' => ['display-capture'],
            'payment' => ['payment'],
            'usb' => ['usb'],
            'midi' => ['midi'],
            'serial' => ['serial'],
            'bluetooth' => ['bluetooth'],
            'hid' => ['hid'],
            'accelerometer' => ['accelerometer'],
            'gyroscope' => ['gyroscope'],
            'magnetometer' => ['magnetometer'],
            'autoplay' => ['autoplay'],
            'fullscreen' => ['fullscreen'],
            'screen wake lock' => ['screen-wake-lock'],
            'web authentication' => ['publickey-credentials-get'],
            'topics tracking' => ['browsing-topics'],
        ];
    }

    #[Test]
    #[DataProvider('deniedFeatures')]
    public function every_powerful_feature_is_denied_on_a_public_page(string $feature): void
    {
        $this->assertStringContainsString(
            $feature.'=()',
            (string) $this->get('/')->assertOk()->headers->get('Permissions-Policy'),
        );
    }

    #[Test]
    #[DataProvider('deniedFeatures')]
    public function every_powerful_feature_is_denied_on_the_panel(string $feature): void
    {
        $this->assertStringContainsString(
            $feature.'=()',
            (string) $this->get('/admin/login')->headers->get('Permissions-Policy'),
        );
    }

    /**
     * Nothing may be quietly allowed. A feature the portal starts using has to
     * be removed from the deny list deliberately, and this fails until it is.
     */
    #[Test]
    public function the_feature_policy_allows_nothing(): void
    {
        $policy = (string) $this->get('/')->assertOk()->headers->get('Permissions-Policy');

        $this->assertNotSame('', $policy);
        $this->assertStringNotContainsString('*', $policy);
        $this->assertStringNotContainsString('self', $policy);
    }

    // --- Cross-origin isolation ------------------------------------------

    /**
     * `Cross-Origin-Embedder-Policy` is deliberately absent, and this records
     * why so nobody adds it expecting an improvement.
     *
     * `require-corp` refuses every cross-origin subresource that does not opt
     * in. Measured on 2026-09-03: the OpenStreetMap tile server answers with
     * `Access-Control-Allow-Origin: *` but **no** `Cross-Origin-Resource-Policy`,
     * and Leaflet requests tiles as plain `<img>` elements with no `crossorigin`
     * attribute, so every tile would be blocked. The SILAM page sends no CORP
     * either, so the forecast iframe would go with it. The header would trade a
     * working map and a working forecast for an isolation the portal has no use
     * for — it runs no `SharedArrayBuffer` and needs no high-resolution timers.
     */
    #[Test]
    public function the_embedder_policy_is_not_set(): void
    {
        foreach (['/', '/silam', '/admin/login'] as $uri) {
            $this->assertNull(
                $this->get($uri)->headers->get('Cross-Origin-Embedder-Policy'),
                "[{$uri}] sends COEP, which would block the map tiles and the SILAM iframe.",
            );
        }
    }

    /**
     * The one external link opens in a new tab. `same-origin` severs the opener
     * relationship, so the FMI page cannot reach back into this one through
     * `window.opener` even if the link's own `rel` were lost in an edit.
     */
    #[Test]
    public function the_silam_page_still_isolates_its_popup(): void
    {
        $this->get('/silam')
            ->assertOk()
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
    }

    // --- The edge and the application agree ------------------------------

    /**
     * nginx sends the static headers too, and hides the application's copies so
     * a client never receives one twice. That only holds while the two say the
     * same thing: a feature added to the middleware and forgotten in the
     * snippet would leave the deployed edge sending the weaker policy, and
     * nothing in the application would notice, because the application's own
     * header is the one being hidden.
     *
     * @return array<string, array{string, string}>
     */
    public static function headersNginxAlsoSends(): array
    {
        return [
            'nosniff' => ['X-Content-Type-Options', 'nosniff'],
            'legacy framing' => ['X-Frame-Options', 'SAMEORIGIN'],
            'referrer' => ['Referrer-Policy', 'strict-origin-when-cross-origin'],
            'cross domain policies' => ['X-Permitted-Cross-Domain-Policies', 'none'],
            'popup isolation' => ['Cross-Origin-Opener-Policy', 'same-origin'],
            'resource embedding' => ['Cross-Origin-Resource-Policy', 'same-origin'],
        ];
    }

    #[Test]
    #[DataProvider('headersNginxAlsoSends')]
    public function the_edge_sends_and_hides_each_static_header(string $header, string $value): void
    {
        $this->assertStringContainsString(
            'add_header '.$header.' "'.$value.'" always;',
            $this->nginxSnippet(),
            "nginx does not send [{$header}].",
        );

        $this->assertStringContainsString(
            'fastcgi_hide_header '.$header.';',
            $this->nginxSite(),
            "nginx does not hide the application's [{$header}], so a client would receive it twice.",
        );
    }

    #[Test]
    public function the_edge_denies_exactly_the_features_the_application_denies(): void
    {
        $this->assertStringContainsString(
            'add_header Permissions-Policy "'.SecurityHeaders::permissionsPolicy().'" always;',
            $this->nginxSnippet(),
            'The nginx feature policy has drifted from the application one.',
        );

        $this->assertStringContainsString(
            'fastcgi_hide_header Permissions-Policy;',
            $this->nginxSite(),
        );
    }

    /**
     * The header nobody may add at the edge either.
     */
    #[Test]
    public function the_edge_does_not_send_an_embedder_policy(): void
    {
        $this->assertStringNotContainsString(
            'add_header Cross-Origin-Embedder-Policy',
            $this->nginxSnippet(),
        );
    }

    private function nginxSnippet(): string
    {
        return (string) file_get_contents(base_path('docker/nginx/snippets/security-headers.conf'));
    }

    private function nginxSite(): string
    {
        return (string) file_get_contents(base_path('docker/nginx/default.conf'));
    }

    #[Test]
    #[DataProvider('requiredHeaders')]
    public function a_public_page_carries_every_baseline_header(string $header, string $value): void
    {
        $this->get('/')->assertOk()->assertHeader($header, $value);
    }

    #[Test]
    #[DataProvider('requiredHeaders')]
    public function an_api_response_carries_every_baseline_header(string $header, string $value): void
    {
        $this->getJson('/api/v1/metadata')->assertOk()->assertHeader($header, $value);
    }

    #[Test]
    public function a_public_page_carries_the_baseline_content_security_policy(): void
    {
        $response = $this->get('/')->assertOk();

        $policy = (string) $response->headers->get('Content-Security-Policy');
        $nonce = $this->extractNonce($policy);

        $this->assertNotNull($nonce);
        $this->assertSame(
            self::BASELINE
                .'; '."script-src 'self' 'nonce-".$nonce."'"
                .'; '."style-src 'self' 'unsafe-inline'",
            $policy,
        );
    }

    /**
     * A response produced by the exception handler never reaches the route, so
     * it never reaches group middleware either. It still has to be hardened.
     */
    #[Test]
    #[DataProvider('requiredHeaders')]
    public function a_response_for_an_unmatched_route_is_still_hardened(string $header, string $value): void
    {
        $this->get('/no-such-page')->assertNotFound()->assertHeader($header, $value);
    }

    #[Test]
    #[DataProvider('requiredHeaders')]
    public function an_api_error_envelope_is_still_hardened(string $header, string $value): void
    {
        $this->getJson('/api/v1/stations/does-not-exist')
            ->assertNotFound()
            ->assertHeader($header, $value);
    }

    /**
     * An unmatched `/api` path never enters the API route group, so the
     * middleware that mints the request id never runs. The envelope has to
     * supply one anyway, or the documented correlation channel has a hole.
     */
    #[Test]
    public function an_unmatched_api_path_still_carries_a_request_id(): void
    {
        $response = $this->getJson('/api/v1/no-such-endpoint')->assertNotFound();

        $this->assertNotNull($response->headers->get('X-Request-Id'));
        $this->assertSame(
            $response->json('error.request_id'),
            $response->headers->get('X-Request-Id'),
        );
    }

    #[Test]
    public function the_admin_panel_is_hardened_too(): void
    {
        $this->get('/admin')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy');
    }

    // --- Script nonce ----------------------------------------------------

    #[Test]
    public function a_public_page_restricts_scripts_to_a_per_request_nonce(): void
    {
        $policy = $this->policyFor('/');

        $this->assertMatchesRegularExpression(
            "/script-src 'self' 'nonce-[A-Za-z0-9]{40}'/",
            $policy,
        );
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $policy);
        $this->assertStringNotContainsString("'unsafe-eval'", $policy);
    }

    /**
     * A reused nonce is no nonce: an attacker who read one response could then
     * predict the value the next one will accept.
     */
    #[Test]
    public function every_response_gets_a_different_nonce(): void
    {
        $this->assertNotSame($this->nonceFor('/'), $this->nonceFor('/'));
    }

    /**
     * The nonce in the header is worthless unless the page's own tags carry the
     * same value. Vite stamps every script, stylesheet and preload tag it
     * renders with whatever `cspNonce()` holds, and Livewire reads the same
     * place, so this asserts the one value both sides depend on.
     *
     * The backend suite runs with `withoutVite()`, so it cannot inspect the
     * rendered tags themselves; that is checked against the production build.
     */
    #[Test]
    public function the_nonce_in_the_header_is_the_one_the_page_tags_will_carry(): void
    {
        $response = $this->get('/')->assertOk();

        $this->assertSame(
            Vite::cspNonce(),
            $this->extractNonce((string) $response->headers->get('Content-Security-Policy')),
        );
    }

    // --- Style policy ----------------------------------------------------

    /**
     * The compatible default. A nonce cannot be attached to an inline `style`
     * attribute, and Leaflet positions every map pane with one, so the default
     * keeps inline styles working while still refusing foreign stylesheets.
     */
    #[Test]
    public function styles_default_to_same_origin_with_inline_allowed(): void
    {
        $policy = $this->policyFor('/');

        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $policy);
        $this->assertStringNotContainsString('style-src-attr', $policy);
    }

    #[Test]
    public function enabling_the_style_nonce_also_permits_inline_style_attributes(): void
    {
        config(['security.csp.style_nonce' => true]);

        $policy = $this->policyFor('/');
        $nonce = $this->extractNonce($policy);

        $this->assertNotNull($nonce);
        $this->assertStringContainsString("style-src 'self' 'nonce-".$nonce."'", $policy);
        // Without this companion the map does not render at all: `style-src`
        // with a nonce refuses every inline style attribute.
        $this->assertStringContainsString("style-src-attr 'unsafe-inline'", $policy);
    }

    #[Test]
    public function the_script_and_style_nonce_are_the_same_value(): void
    {
        config(['security.csp.style_nonce' => true]);

        $policy = $this->policyFor('/');

        $this->assertSame(
            2,
            preg_match_all("/'nonce-([A-Za-z0-9]{40})'/", $policy, $matches),
        );
        $this->assertSame($matches[1][0], $matches[1][1]);
    }

    // --- Panel -----------------------------------------------------------

    /**
     * Filament renders inline scripts with no nonce support and Alpine compiles
     * expressions with `new Function`, so the panel states its concession
     * instead of shipping a policy that leaves it blank. This asserts the
     * concession stays confined to the panel.
     */
    #[Test]
    public function the_panel_concession_does_not_reach_the_public_site(): void
    {
        $panel = $this->policyFor('/admin/login');

        $this->assertStringContainsString("script-src 'self' 'unsafe-inline' 'unsafe-eval'", $panel);
        $this->assertStringNotContainsString('nonce-', $panel);

        $public = $this->policyFor('/');

        $this->assertStringNotContainsString("'unsafe-eval'", $public);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $public);
    }

    #[Test]
    public function the_panel_still_keeps_the_directives_that_need_no_nonce(): void
    {
        $policy = $this->policyFor('/admin/login');

        foreach (["base-uri 'self'", "form-action 'self'", "frame-ancestors 'self'", "object-src 'none'"] as $directive) {
            $this->assertStringContainsString($directive, $policy);
        }
    }

    // --- Fetch directives ------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function surfaces(): array
    {
        return [
            'the public portal' => ['/'],
            'the administration panel' => ['/admin/login'],
            'a JSON endpoint' => ['/api/v1/metadata'],
        ];
    }

    /**
     * A policy that names no `default-src` restricts only what it lists.
     *
     * That is how this one used to read: `script-src` and `style-src` were
     * stated, and `connect-src`, `img-src`, `font-src`, `media-src`,
     * `worker-src` and `manifest-src` were left unrestricted — so an injected
     * script could still `fetch()` anywhere, which is the channel that turns a
     * scripting bug into a data breach. The panel's `unsafe-inline` concession
     * makes this worse there, not better, which is exactly why it is closed on
     * every surface rather than only the public one.
     */
    #[Test]
    #[DataProvider('surfaces')]
    public function every_surface_closes_the_fetch_directives(string $uri): void
    {
        $policy = $this->policyFor($uri);

        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("connect-src 'self'", $policy);
        $this->assertStringContainsString("font-src 'self'", $policy);
    }

    /**
     * The map is the portal's one legitimate third-party image source. It is
     * named, so an image URL cannot be used to carry data to an arbitrary host,
     * and `https:` as a whole is never opened.
     */
    #[Test]
    #[DataProvider('surfaces')]
    public function images_come_from_this_host_the_tile_server_or_a_data_uri(string $uri): void
    {
        $policy = $this->policyFor($uri);

        $this->assertStringContainsString(
            "img-src 'self' data: ".config('security.csp.map_tile_origin'),
            $policy,
        );
        $this->assertStringNotContainsString('img-src https:;', $policy);
    }

    /**
     * The policy and the map have to agree about the tile host: a policy naming
     * one and a map requesting another produces a blank map, and nothing else
     * would notice. Asserted against the component itself rather than against a
     * second copy of the hostname.
     */
    #[Test]
    public function the_configured_tile_origin_matches_the_url_the_map_requests(): void
    {
        $origin = (string) config('security.csp.map_tile_origin');
        $component = (string) file_get_contents(base_path('resources/js/components/station-map.tsx'));

        if (preg_match('#url="https://\{s\}\.([^/"]+)/#', $component, $matches) !== 1) {
            $this->fail('The station map no longer requests tiles in the expected shape.');
        }

        $this->assertSame('https://*.'.$matches[1], $origin);
    }

    /**
     * An unusable configuration must close the directive rather than open it.
     */
    #[Test]
    public function an_empty_tile_origin_leaves_images_same_origin_only(): void
    {
        config(['security.csp.map_tile_origin' => '']);

        $this->assertStringContainsString(
            "img-src 'self' data: 'none'",
            (string) ContentSecurityPolicy::baseline(),
        );
    }

    // --- Policy object ---------------------------------------------------

    #[Test]
    public function directives_are_emitted_in_a_stable_order(): void
    {
        $composed = ContentSecurityPolicy::baseline()
            ->withStyleNonce('abc')
            ->withScriptNonce('abc')
            ->allowingFrameSources('https://example.org');

        $reordered = ContentSecurityPolicy::baseline()
            ->allowingFrameSources('https://example.org')
            ->withScriptNonce('abc')
            ->withStyleNonce('abc');

        $this->assertSame((string) $composed, (string) $reordered);
    }

    #[Test]
    public function the_baseline_alone_restricts_neither_scripts_nor_styles(): void
    {
        $policy = (string) ContentSecurityPolicy::baseline();

        $this->assertStringNotContainsString('script-src', $policy);
        $this->assertStringNotContainsString('style-src', $policy);
    }

    #[Test]
    public function an_empty_frame_source_list_leaves_the_frame_directive_closed(): void
    {
        $this->assertStringContainsString(
            "frame-src 'none'",
            (string) ContentSecurityPolicy::baseline()->allowingFrameSources(),
        );
    }

    // --- Embed origin ----------------------------------------------------

    /**
     * @return array<string, array{string|null}>
     */
    public static function unusableEmbedUrls(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'blank' => ['   '],
            'plain http' => ['http://silam.fmi.fi/roux/TAJ/'],
            'protocol relative' => ['//silam.fmi.fi/roux/TAJ/'],
            'relative path' => ['/roux/TAJ/'],
            'javascript scheme' => ['javascript:alert(1)'],
            'data scheme' => ['data:text/html,<h1>x</h1>'],
            'no host' => ['https:///roux/'],
        ];
    }

    #[Test]
    #[DataProvider('unusableEmbedUrls')]
    public function an_unusable_embed_url_yields_no_origin(?string $url): void
    {
        $this->assertNull(EmbedOrigin::fromUrl($url));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function usableEmbedUrls(): array
    {
        return [
            'path is dropped' => ['https://silam.fmi.fi/roux/TAJ/', 'https://silam.fmi.fi'],
            'query is dropped' => ['https://silam.fmi.fi/roux/?a=b', 'https://silam.fmi.fi'],
            'port is kept' => ['https://silam.fmi.fi:8443/roux/', 'https://silam.fmi.fi:8443'],
            'surrounding space' => ['  https://silam.fmi.fi/roux/  ', 'https://silam.fmi.fi'],
        ];
    }

    #[Test]
    #[DataProvider('usableEmbedUrls')]
    public function a_usable_embed_url_is_reduced_to_its_origin(string $url, string $expected): void
    {
        $this->assertSame($expected, EmbedOrigin::fromUrl($url));
    }

    // --- Helpers ---------------------------------------------------------

    private function policyFor(string $uri): string
    {
        return (string) $this->get($uri)->headers->get('Content-Security-Policy');
    }

    private function nonceFor(string $uri): ?string
    {
        return $this->extractNonce($this->policyFor($uri));
    }

    private function extractNonce(string $policy): ?string
    {
        return preg_match("/'nonce-([A-Za-z0-9]{40})'/", $policy, $matches) === 1
            ? $matches[1]
            : null;
    }
}
