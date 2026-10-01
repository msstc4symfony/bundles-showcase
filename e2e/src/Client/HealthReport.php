<?php

declare(strict_types=1);

namespace App\E2e\Client;

final readonly class HealthReport
{
    public function __construct(
        public int $status,
        public string $body,
    ) {
    }

    public function isUp(): bool
    {
        return $this->status === 200 && str_contains($this->body, 'Result: up');
    }

    /**
     * Down means the service answered and reported a failed dependency, not that it is unreachable.
     */
    public function isDown(): bool
    {
        return $this->status !== 0 && !$this->isUp();
    }
}
