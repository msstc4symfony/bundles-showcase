<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure;

use App\Infrastructure\RedisConnectionGuard;
use App\Tests\Support\FakeRedis;
use Baldinof\RoadRunnerBundle\Event\ForceKernelRebootEvent;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;

final class RedisConnectionGuardTest extends TestCase
{
    /** @var list<string> */
    private array $reboots = [];

    public function testAHealthyConnectionKeepsTheKernel(): void
    {
        $this->guard(alive: true)->onTerminate();

        self::assertSame([], $this->reboots);
    }

    public function testABrokenConnectionRebootsTheKernelAfterTheResponse(): void
    {
        $this->guard(alive: false)->onTerminate();

        self::assertCount(1, $this->reboots);
    }

    public function testABrokenConnectionStopsTheMessengerWorker(): void
    {
        $worker = new Worker([], self::createStub(MessageBusInterface::class));

        $this->guard(alive: false)->onWorkerRunning(new WorkerRunningEvent($worker, true));

        self::assertTrue($this->stopped($worker));
    }

    public function testAHealthyConnectionKeepsTheWorkerRunning(): void
    {
        $worker = new Worker([], self::createStub(MessageBusInterface::class));

        $this->guard(alive: true)->onWorkerRunning(new WorkerRunningEvent($worker, true));

        self::assertFalse($this->stopped($worker));
    }

    private function stopped(Worker $worker): bool
    {
        return new ReflectionProperty(Worker::class, 'shouldStop')->getValue($worker) === true;
    }

    private function guard(bool $alive): RedisConnectionGuard
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ForceKernelRebootEvent::class, function (ForceKernelRebootEvent $event): void {
            $this->reboots[] = $event->getReason();
        });

        return new RedisConnectionGuard(new FakeRedis($alive), $dispatcher);
    }
}
