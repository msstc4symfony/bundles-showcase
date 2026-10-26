<?php

declare(strict_types=1);

namespace App\E2e\Client;

/**
 * A trace read from Tempo, or why there is none yet.
 */
final readonly class TraceLookup
{
    public function __construct(
        public ?TraceTree $tree,
        public string $reason,
        public bool $notFound = false,
    ) {
    }
}
