<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Msstc4Symfony\SloBundle\Application\Port\OperationHandleInterface;
use Override;
use RuntimeException;
use Throwable;

final readonly class ThrowingOperationHandle implements OperationHandleInterface
{
    #[Override]
    public function succeed(): void
    {
        throw new RuntimeException('succeed failed');
    }

    #[Override]
    public function failExpected(?Throwable $reason = null): void
    {
        throw new RuntimeException('failExpected failed');
    }

    #[Override]
    public function fail(?Throwable $reason = null): void
    {
        throw new RuntimeException('fail failed');
    }

    #[Override]
    public function end(): void
    {
        throw new RuntimeException('end failed');
    }
}
