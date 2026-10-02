<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure;

use App\Infrastructure\RedisConnectionGuard;
use App\Tests\Support\FakeRedis;
use Baldinof\RoadRunnerBundle\Event\ForceKernelRebootEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;

final class RedisConnectionGuardTest extends TestCase
{
    private const int LOOPS_BEFORE_THE_TEST_STOPS_THE_WORKER = 3;

    /** @var list<string> */
    private array $reboots = [];

    public function testAHealthyConnectionKeepsTheKernel(): void
    {
        $this->guard(new FakeRedis(true), new MockClock())->onTerminate();

        self::assertSame([], $this->reboots);
    }

    public function testABrokenConnectionRebootsTheKernelAfterTheResponse(): void
    {
        $this->guard(new FakeRedis(false), new MockClock())->onTerminate();

        self::assertCount(1, $this->reboots);
    }

    public function testABrokenConnectionStopsTheMessengerWorker(): void
    {
        self::assertSame(1, $this->workerLoops($this->guard(new FakeRedis(false), new MockClock())));
    }

    public function testAHealthyConnectionKeepsTheWorkerRunning(): void
    {
        self::assertSame(self::LOOPS_BEFORE_THE_TEST_STOPS_THE_WORKER, $this->workerLoops($this->guard(new FakeRedis(true), new MockClock())));
    }

    public function testTheWorkerLoopPingsAtMostOncePerInterval(): void
    {
        $redis = new FakeRedis(true);
        $clock = new MockClock();
        $guard = $this->guard($redis, $clock);

        $this->workerLoops($guard);
        self::assertSame(1, $redis->pings);

        $clock->sleep(RedisConnectionGuard::WORKER_PING_INTERVAL_SECONDS);
        $this->workerLoops($guard);
        self::assertSame(2, $redis->pings);
    }

    /**
     * Runs a real worker without receivers until the guard or the test stops it; returns the loop count.
     */
    private function workerLoops(RedisConnectionGuard $guard): int
    {
        $dispatcher = new EventDispatcher();
        $loops = 0;
        $dispatcher->addListener(WorkerRunningEvent::class, $guard->onWorkerRunning(...), 10);
        $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event) use (&$loops): void {
            if (++$loops >= self::LOOPS_BEFORE_THE_TEST_STOPS_THE_WORKER) {
                $event->getWorker()->stop();
            }
        });

        new Worker([], self::createStub(MessageBusInterface::class), $dispatcher)->run(['sleep' => 0]);

        return $loops;
    }

    private function guard(FakeRedis $redis, MockClock $clock): RedisConnectionGuard
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ForceKernelRebootEvent::class, function (ForceKernelRebootEvent $event): void {
            $this->reboots[] = $event->getReason();
        });

        return new RedisConnectionGuard($redis, $dispatcher, $clock);
    }
}
