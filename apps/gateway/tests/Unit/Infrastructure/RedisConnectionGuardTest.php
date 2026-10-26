<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure;

use App\Infrastructure\RedisConnectionGuard;
use App\Tests\Support\FakeRedis;
use App\Tests\Support\UnreachableLockStore;
use Baldinof\RoadRunnerBundle\Event\ForceKernelRebootEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

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

        self::assertSame(['The Redis cache connection is broken.'], $this->reboots);
    }

    public function testABrokenLockConnectionRebootsTheKernelWhileTheCacheOneIsAlive(): void
    {
        $this->guard(alive: true, lockAlive: false)->onTerminate();

        self::assertSame(['The lock store connection is broken.'], $this->reboots);
    }

    private function guard(bool $alive, bool $lockAlive = true): RedisConnectionGuard
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ForceKernelRebootEvent::class, function (ForceKernelRebootEvent $event): void {
            $this->reboots[] = $event->getReason();
        });

        $locks = new LockFactory($lockAlive ? new InMemoryStore() : new UnreachableLockStore());

        return new RedisConnectionGuard(new FakeRedis($alive), $locks, $dispatcher);
    }
}
