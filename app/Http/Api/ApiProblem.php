<?php

namespace App\Http\Api;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A safe, stable public API problem. No upstream payload belongs here.
 */
class ApiProblem extends HttpException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        int $status,
        public readonly string $problemCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($status, $message);
    }
}
