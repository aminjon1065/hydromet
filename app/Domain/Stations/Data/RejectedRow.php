<?php

namespace App\Domain\Stations\Data;

use App\Domain\Stations\Enums\RejectionReason;

/**
 * A single row the portal refused to import.
 *
 * Everything here is safe to log, print and later show to an operator: a stable
 * reason code, a sanitized row reference and a short English explanation.
 * Provider payloads, credentials, SQL and stack traces never reach this object.
 */
final readonly class RejectedRow
{
    private function __construct(
        public string $reference,
        public RejectionReason $reason,
        public string $detail,
    ) {}

    public static function make(string $reference, RejectionReason $reason, string $detail): self
    {
        return new self(
            self::sanitize($reference, 80),
            $reason,
            self::sanitize($detail, 200),
        );
    }

    /**
     * Reduce untrusted upstream text to a short, printable single line.
     *
     * Upstream registry text is untrusted input (CLAUDE.md). Control characters
     * are removed so a rejection can never inject terminal escapes or newlines
     * into console output and logs.
     */
    public static function sanitize(string $value, int $limit): string
    {
        $printable = preg_replace('/[\p{C}]+/u', ' ', $value) ?? '';
        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $printable));

        if ($collapsed === '') {
            return '(empty)';
        }

        if (mb_strlen($collapsed) <= $limit) {
            return $collapsed;
        }

        return mb_substr($collapsed, 0, $limit - 1).'…';
    }
}
