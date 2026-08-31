<?php

namespace Tests\Support;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;

/**
 * Captures what was actually written to the log, message and context together.
 *
 * Assertions about "nothing sensitive was logged" are only worth anything if
 * they read the real record rather than a mock's expectations, so this listens
 * for the framework's own MessageLogged event.
 */
trait CapturesLogs
{
    /** @var list<MessageLogged> */
    private array $capturedLogs = [];

    protected function captureLogs(): void
    {
        $this->capturedLogs = [];

        Log::listen(function (MessageLogged $message): void {
            $this->capturedLogs[] = $message;
        });
    }

    /**
     * @return list<MessageLogged>
     */
    protected function loggedMessages(): array
    {
        return $this->capturedLogs;
    }

    /**
     * Everything that reached the log, flattened into one searchable string:
     * levels, messages and every scalar in every context array.
     */
    protected function loggedText(): string
    {
        $parts = [];

        foreach ($this->capturedLogs as $log) {
            $parts[] = $log->level;
            $parts[] = $log->message;
            $parts[] = json_encode($log->context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }

        return implode("\n", $parts);
    }
}
