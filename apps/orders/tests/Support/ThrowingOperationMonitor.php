<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Closure;
use Msstc4Symfony\SloBundle\Application\Port\OperationHandleInterface;
use Msstc4Symfony\SloBundle\Application\Port\OperationMonitorInterface;
use Override;
use RuntimeException;

/**
 * A monitor that breaks its fail-open contract: start() throws, or the handle it returns throws on every call.
 */
final readonly class ThrowingOperationMonitor implements OperationMonitorInterface
{
    public function __construct(
        private bool $startThrows,
    ) {
    }

    #[Override]
    public function start(string $name): OperationHandleInterface
    {
        if ($this->startThrows) {
            throw new RuntimeException('start failed');
        }

        return new ThrowingOperationHandle();
    }

    /**
     * @template T of mixed
     *
     * @param Closure(): T $operation
     *
     * @return T
     */
    #[Override]
    public function measure(string $name, Closure $operation): mixed
    {
        throw new RuntimeException('measure failed');
    }
}
