<?php

namespace App\Domain\Integrations\Data;

/**
 * Comparison between signed/source-supplied totals and the imported copy.
 */
final readonly class ReconciliationReport
{
    public function __construct(
        public ReconciliationSnapshot $expected,
        public ReconciliationSnapshot $actual,
    ) {}

    /**
     * @return list<array{field: string, expected: mixed, actual: mixed}>
     */
    public function differences(): array
    {
        $actual = $this->actual->toArray();
        $differences = [];

        foreach ($this->expected->toArray() as $field => $expected) {
            if ($expected === $actual[$field]) {
                continue;
            }

            $differences[] = [
                'field' => $field,
                'expected' => $expected,
                'actual' => $actual[$field],
            ];
        }

        return $differences;
    }

    public function matches(): bool
    {
        return $this->differences() === [];
    }
}
