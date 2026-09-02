<?php

namespace App\Http\Security;

use Stringable;

/**
 * The portal's Content Security Policy, defined once.
 *
 * The baseline holds only directives that are safe for every response. Script
 * and style sources are layered on top per surface, because the public Inertia
 * shell and the Filament panel do not have the same capabilities:
 *
 * - the public pages take a per-request nonce, so an injected `<script>` is
 *   refused unless it carries a value the attacker cannot predict;
 * - the panel cannot, because Filament and Livewire render inline scripts with
 *   no nonce support and Alpine evaluates expressions through `new Function`.
 *   Its policy states that plainly instead of pretending otherwise.
 *
 * Directives are emitted in alphabetical order so the header is stable no
 * matter which order a caller composed them in.
 */
final class ContentSecurityPolicy implements Stringable
{
    /**
     * @param  array<string, string>  $directives
     */
    private function __construct(private readonly array $directives) {}

    public static function baseline(): self
    {
        return new self([
            'base-uri' => "'self'",
            'form-action' => "'self'",
            'frame-ancestors' => "'self'",
            'frame-src' => "'none'",
            'object-src' => "'none'",
        ]);
    }

    /**
     * Restricts scripts to same-origin files and inline scripts carrying this
     * request's nonce.
     *
     * `'self'` stays alongside the nonce because the entry module imports its
     * page chunks dynamically, and those are same-origin script files rather
     * than inline scripts. `'unsafe-eval'` is deliberately absent: nothing in
     * the public bundle evaluates strings as code.
     */
    public function withScriptNonce(string $nonce): self
    {
        return $this->with([
            'script-src' => "'self' 'nonce-".$nonce."'",
        ]);
    }

    /**
     * Restricts stylesheets to same-origin files and `<style>` elements
     * carrying this request's nonce.
     *
     * `style-src-attr 'unsafe-inline'` comes with it and is not optional: a
     * nonce cannot be attached to an inline `style` attribute, and Leaflet
     * positions every map pane with one. Without the companion directive the
     * map does not render at all.
     *
     * That companion is also why this is not the default. `style-src-attr` is
     * a CSP Level 3 directive; a browser that does not implement it ignores it
     * and falls back to `style-src`, where the nonce blocks the same style
     * attributes. See `config/security.php`.
     */
    public function withStyleNonce(string $nonce): self
    {
        return $this->with([
            'style-src' => "'self' 'nonce-".$nonce."'",
            'style-src-attr' => "'unsafe-inline'",
        ]);
    }

    /**
     * Permits inline styles without a nonce, while still refusing stylesheets
     * from any other origin.
     */
    public function withInlineStyles(): self
    {
        return $this->with([
            'style-src' => "'self' 'unsafe-inline'",
        ]);
    }

    /**
     * The concession the administration panel needs.
     *
     * Filament renders inline `<script>` and `<style>` blocks that take no
     * nonce, and Alpine compiles its expressions with `new Function`. Both
     * allowances are real weakenings; they are confined to authenticated panel
     * routes rather than applied to the public portal.
     */
    public function withInlineAndEvaluatedScripts(): self
    {
        return $this->with([
            'script-src' => "'self' 'unsafe-inline' 'unsafe-eval'",
            'style-src' => "'self' 'unsafe-inline'",
        ]);
    }

    /**
     * Permits the given origins to be framed, in addition to the portal itself.
     *
     * An empty origin list leaves `frame-src 'none'` in place. That is the
     * deliberate outcome when a configured embed URL cannot be reduced to a
     * safe origin: the frame stays blocked rather than the policy being widened
     * to something unvalidated.
     */
    public function allowingFrameSources(string ...$origins): self
    {
        if ($origins === []) {
            return $this;
        }

        return $this->with([
            'frame-src' => implode(' ', ["'self'", ...$origins]),
        ]);
    }

    /**
     * @param  array<string, string>  $directives
     */
    private function with(array $directives): self
    {
        return new self([...$this->directives, ...$directives]);
    }

    public function __toString(): string
    {
        $directives = $this->directives;
        ksort($directives);

        $rendered = [];

        foreach ($directives as $directive => $value) {
            $rendered[] = $directive.' '.$value;
        }

        return implode('; ', $rendered);
    }
}
