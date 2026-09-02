<?php

namespace App\Http\Security;

/**
 * Reduces a configured embed URL to the origin a frame policy may name.
 *
 * The URL is operator-supplied configuration, so it is treated as untrusted
 * input: anything that is not an absolute `https://` URL with a host yields
 * `null`, and the caller keeps the frame blocked.
 */
final class EmbedOrigin
{
    public static function fromUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $parts = parse_url(trim($url));

        if ($parts === false) {
            return null;
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        // Plain http would let the embed be replaced in transit, and a CSP
        // source expression carries no path, so a relative URL cannot be one.
        if ($scheme !== 'https' || ! is_string($host) || $host === '') {
            return null;
        }

        $port = $parts['port'] ?? null;

        return 'https://'.$host.(is_int($port) ? ':'.$port : '');
    }
}
