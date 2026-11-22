<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Msstc4Symfony\SloBundle\Application\Port\OperationHandleInterface;
use Override;
use Throwable;

final readonly class RecordingOperationHandle implements OperationHandleInterface
{
    public function __construct(
        private RecordingOperationMonitor $monitor,
    ) {
    }

    #[Override]
    public function succeed(): void
    {
        $this->monitor->record('succeed');
    }

    #[Override]
    public function failExpected(?Throwable $reason = null): void
    {
        $this->monitor->recordFailure($reason, expected: true);
    }

    #[Override]
    public function fail(?Throwable $reason = null): void
    {
        $this->monitor->recordFailure($reason, expected: false);
    }

    #[Override]
    public function end(): void
    {
        $this->monitor->record('end');
    }
}
