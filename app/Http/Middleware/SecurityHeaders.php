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
     * @var array<string, string>
     */
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        // Superseded by `frame-ancestors` in current browsers, kept for older
        // ones that never implemented it.
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'X-Permitted-Cross-Domain-Policies' => 'none',
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
