<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Closure;
use Msstc4Symfony\SloBundle\Application\Port\OperationHandleInterface;
use Msstc4Symfony\SloBundle\Application\Port\OperationMonitorInterface;
use Override;
use Throwable;

final class RecordingOperationMonitor implements OperationMonitorInterface
{
    /** @var list<non-empty-string> e.g. "start orders.search_index", "succeed", "end" */
    private array $calls = [];

    private ?Throwable $failure = null;

    private ?Throwable $expectedFailure = null;

    #[Override]
    public function start(string $name): OperationHandleInterface
    {
        $this->calls[] = 'start ' . $name;

        return new RecordingOperationHandle($this);
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
        $this->calls[] = 'measure ' . $name;

        return $operation();
    }

    /**
     * @param non-empty-string $call
     */
    public function record(string $call): void
    {
        $this->calls[] = $call;
    }

    public function recordFailure(?Throwable $reason, bool $expected): void
    {
        $this->calls[] = $expected ? 'failExpected' : 'fail';
        if ($expected) {
            $this->expectedFailure ??= $reason;
        } else {
            $this->failure ??= $reason;
        }
    }

    /**
     * @return list<non-empty-string>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function failure(): ?Throwable
    {
        return $this->failure;
    }

    public function expectedFailure(): ?Throwable
    {
        return $this->expectedFailure;
    }
}
