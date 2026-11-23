<?php

declare(strict_types=1);

namespace App\E2e\Client;

final readonly class NotifiedAlert
{
    /**
     * @param 'firing'|'resolved' $status
     * @param array<non-empty-string, string> $labels
     */
    public function __construct(
        public string $status,
        public array $labels,
    ) {
    }
}
