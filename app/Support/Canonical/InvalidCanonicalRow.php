<?php

namespace App\Support\Canonical;

use RuntimeException;

/**
 * A canonical row could not be read or is not acceptable.
 *
 * The message is written for operators and never contains provider payloads,
 * credentials or driver output. Callers turn it into a
 * {@see RejectedRow} and keep importing.
 */
final class InvalidCanonicalRow extends RuntimeException
{
    public function __construct(
        public readonly RejectionReason $reason,
        string $safeDetail,
    ) {
        parent::__construct($safeDetail);
    }

    public function safeDetail(): string
    {
        return $this->getMessage();
    }
}
