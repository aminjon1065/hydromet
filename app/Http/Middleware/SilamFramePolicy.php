<?php

namespace App\Http\Middleware;

use App\Http\Security\EmbedOrigin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Widens the public policy just enough to frame the configured SILAM view.
 *
 * The origin is derived from `services.silam.url` rather than hard-coded, so
 * changing `SILAM_URL` cannot leave the page rendering an iframe the policy
 * then blocks. If that URL is not a usable https origin the baseline
 * `frame-src 'none'` stands: an unusable embed is visible as an empty frame
 * with a working fallback link, which is preferable to permitting an origin
 * the portal could not validate.
 *
 * Everything else — including the script nonce — comes from the same builder
 * the rest of the site uses, so this page cannot drift into a weaker policy.
 */
class SilamFramePolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $configured = config('services.silam.url');
        $origin = EmbedOrigin::fromUrl(is_string($configured) ? $configured : null);

        $policy = SecurityHeaders::publicPolicy(SecurityHeaders::nonce($request))
            ->allowingFrameSources(...array_filter([$origin]));

        $response->headers->set('Content-Security-Policy', (string) $policy);

        return $response;
    }
}
