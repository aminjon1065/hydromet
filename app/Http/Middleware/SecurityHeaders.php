<?php

namespace App\Http\Middleware;

use App\Http\Security\ContentSecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the portal's baseline response security headers.
 *
 * The reverse proxy in `docker/nginx/default.conf` sets the same static
 * headers, and deliberately hides the application's copies so a client never
 * receives a header twice — a repeated `X-Content-Type-Options` is treated as
 * invalid by browsers and silently disables the protection it was meant to
 * add. Setting them here as well means the guarantee does not depend on one
 * proxy configuration file, survives a different front end, and can be proven
 * by the test suite instead of by reading nginx.
 *
 * The Content Security Policy is not static, so nginx cannot send it: it
 * carries a nonce minted for this request.
 */
class SecurityHeaders
{
    /**
     * Request attribute under which the nonce is published, so a route that
     * pins its own policy builds on the same value the page was rendered with.
     */
    public const NONCE_ATTRIBUTE = 'csp_nonce';

    /**
     * Powerful browser features, every one denied.
     *
     * The portal uses none of them: there is no geolocation, no media capture,
     * no payment and no sensor reading anywhere in the bundle. An empty
     * allowlist refuses the feature to this page *and* to anything it frames,
     * which is the point — without the header, an injected script or the framed
     * SILAM page could prompt a visitor for their location or their camera
     * under this portal's name, and an official government site is exactly
     * where such a prompt would be believed.
     *
     * Listed explicitly rather than by a wildcard, because there is no
     * "deny everything" form: a feature absent from the header is allowed. A
     * feature the portal starts using must therefore be removed from this list
     * deliberately, which is what `SecurityHeadersTest` holds it to.
     *
     * @var array<int, string>
     */
    private const DENIED_FEATURES = [
        'accelerometer',
        'autoplay',
        'bluetooth',
        'browsing-topics',
        'camera',
        'display-capture',
        'fullscreen',
        'geolocation',
        'gyroscope',
        'hid',
        'magnetometer',
        'microphone',
        'midi',
        'payment',
        'publickey-credentials-get',
        'screen-wake-lock',
        'serial',
        'usb',
    ];

    /**
     * @var array<string, string>
     */
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        // Superseded by `frame-ancestors` in current browsers, kept for older
        // ones that never implemented it.
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'X-Permitted-Cross-Domain-Policies' => 'none',
        // A page opened from here — the SILAM link is the only one — lands in
        // its own browsing-context group, so it cannot reach back through
        // `window.opener`. The link already carries `rel="noreferrer"`; this
        // makes the guarantee a property of the response rather than of one
        // attribute that an edit could drop.
        'Cross-Origin-Opener-Policy' => 'same-origin',
        // Nothing here is meant to be embedded by another site. Assets are
        // served from this origin to this origin; if they are ever moved to a
        // separate asset host, this becomes `same-site` and the move has to be
        // deliberate rather than silent.
        'Cross-Origin-Resource-Policy' => 'same-origin',
        //
        // `Cross-Origin-Embedder-Policy` is deliberately absent. `require-corp`
        // refuses every cross-origin subresource that does not opt in, and
        // neither of the portal's two external dependencies does: the
        // OpenStreetMap tile server sends no `Cross-Origin-Resource-Policy`
        // (measured 2026-09-03) and Leaflet fetches tiles as plain `<img>`
        // elements, so the map would go blank; the SILAM page sends none
        // either, so the forecast iframe would go with it. The portal runs no
        // `SharedArrayBuffer` and needs no high-resolution timers, so the
        // header would cost two working features and buy nothing.
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Minted before the response is produced, because the tags Vite renders
        // into the page have to carry the same value the header names. Laravel
        // generates and remembers it; Livewire reads it from the same place.
        $nonce = Vite::useCspNonce();
        $request->attributes->set(self::NONCE_ATTRIBUTE, $nonce);

        $response = $next($request);

        foreach (self::HEADERS as $header => $value) {
            $response->headers->set($header, $value);
        }

        $response->headers->set('Permissions-Policy', self::permissionsPolicy());

        // A route or panel that pinned its own policy is more specific than the
        // baseline. Inner middleware has already run by the time this global
        // middleware sees the response, so the check is what keeps the narrower
        // policy from being overwritten by the broader one.
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set(
                'Content-Security-Policy',
                (string) self::publicPolicy($nonce),
            );
        }

        return $response;
    }

    /**
     * The feature policy, as a structured header: `camera=(), microphone=(), …`
     *
     * Built from the list rather than written out, so the header and the list
     * cannot disagree, and emitted in the order declared — which is
     * alphabetical — so the value is stable between responses.
     */
    public static function permissionsPolicy(): string
    {
        return implode(', ', array_map(
            static fn (string $feature): string => $feature.'=()',
            self::DENIED_FEATURES,
        ));
    }

    /**
     * The policy every public response carries unless a route narrows it.
     */
    public static function publicPolicy(string $nonce): ContentSecurityPolicy
    {
        $policy = ContentSecurityPolicy::baseline()->withScriptNonce($nonce);

        return config('security.csp.style_nonce') === true
            ? $policy->withStyleNonce($nonce)
            : $policy->withInlineStyles();
    }

    /**
     * The nonce minted for this request, or a fresh one if the middleware has
     * not run — which happens only outside the HTTP stack.
     */
    public static function nonce(Request $request): string
    {
        $nonce = $request->attributes->get(self::NONCE_ATTRIBUTE);

        return is_string($nonce) && $nonce !== '' ? $nonce : Vite::useCspNonce();
    }
}
