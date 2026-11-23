<?php

declare(strict_types=1);

namespace App\E2e\Client;

/**
 * An alert as Alertmanager holds it now (`/api/v2/alerts`).
 */
final readonly class ManagedAlert
{
    /**
     * @param non-empty-string $fingerprint
     * @param array<non-empty-string, string> $labels
     * @param 'active'|'suppressed'|'unprocessed' $state
     * @param list<string> $inhibitedBy fingerprints of the alerts that inhibit this one
     */
    public function __construct(
        public string $fingerprint,
        public array $labels,
        public string $state,
        public array $inhibitedBy,
    ) {
    }
}
