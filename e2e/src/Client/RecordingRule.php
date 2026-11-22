<?php

declare(strict_types=1);

namespace App\E2e\Client;

/**
 * A recording rule as Prometheus loaded it (`/api/v1/rules`).
 */
final readonly class RecordingRule
{
    /**
     * @param non-empty-string $record
     * @param non-empty-string $query
     * @param array<non-empty-string, string> $labels
     * @param ?float $lastEvaluationUnix null while the rule has not been evaluated yet
     */
    public function __construct(
        public string $record,
        public string $query,
        public array $labels,
        public string $health,
        public ?float $lastEvaluationUnix,
    ) {
    }
}
