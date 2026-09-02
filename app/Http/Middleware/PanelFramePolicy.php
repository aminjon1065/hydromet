<?php

namespace App\Http\Middleware;

use App\Http\Security\ContentSecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The administration panel's Content Security Policy.
 *
 * The panel cannot use the public nonce policy. Filament renders inline
 * `<script>` and `<style>` blocks from its own Blade views, which accept no
 * nonce, and the Alpine build it ships compiles every `x-` expression with
 * `new Function`. A nonce policy here would leave a blank panel, not a safer
 * one.
 *
 * So the panel states its concession explicitly and keeps it contained: the
 * allowance applies only to authenticated panel routes, while the public
 * portal — the surface an anonymous visitor can reach — keeps the nonce. The
 * directives that need no nonce (`base-uri`, `form-action`, `frame-ancestors`,
 * `frame-src`, `object-src`) still apply here.
 *
 * Removing the concession means either publishing and patching Filament's Blade
 * views, or waiting for upstream nonce support. It is recorded as an open item
 * rather than left implicit.
 */
class PanelFramePolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set(
            'Content-Security-Policy',
            (string) ContentSecurityPolicy::baseline()->withInlineAndEvaluatedScripts(),
        );

        return $response;
    }
}
