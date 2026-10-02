<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure;

use App\Infrastructure\RedisConnectionGuard;
use App\Tests\Support\FakeRedis;
use Baldinof\RoadRunnerBundle\Event\ForceKernelRebootEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

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

    private function guard(bool $alive): RedisConnectionGuard
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ForceKernelRebootEvent::class, function (ForceKernelRebootEvent $event): void {
            $this->reboots[] = $event->getReason();
        });

        return new RedisConnectionGuard(new FakeRedis($alive), $dispatcher);
    }
}
