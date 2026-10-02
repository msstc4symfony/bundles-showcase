<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Baldinof\RoadRunnerBundle\Event\ForceKernelRebootEvent;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Redis;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\WhenNot;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * phpredis 6 leaves a client whose command hit a Redis outage failed for good ("went away"), and
 * Symfony never re-creates its Redis connections (cache, lock). Long-running processes therefore
 * rebuild them: RoadRunner reboots the kernel after the response, a Messenger worker exits and
 * is restarted by the orchestrator. While Redis stays down this repeats on every request / start.
 *
 * Only the cache connection is probed: it shares the Redis server with every other connection here,
 * so it breaks together with them. A store on another server would need its own probe.
 */
#[WhenNot(env: 'test')]
final class RedisConnectionGuard
{
    public const int WORKER_PING_INTERVAL_SECONDS = 5;

    private ?DateTimeImmutable $lastWorkerPing = null;

    public function __construct(
        #[Autowire(service: 'cache.default_redis_provider')]
        private readonly Redis $redis,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ClockInterface $clock,
    ) {
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onTerminate(): void
    {
        if (!$this->isAlive()) {
            $this->dispatcher->dispatch(new ForceKernelRebootEvent('The Redis connection is broken.'));
        }
    }

    #[AsEventListener(event: WorkerRunningEvent::class)]
    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        // The worker loop runs every second or faster; a ping per loop would be pure overhead.
        $now = $this->clock->now();
        if ($this->lastWorkerPing instanceof DateTimeImmutable
            && (float) $now->format('U.u') - (float) $this->lastWorkerPing->format('U.u') < self::WORKER_PING_INTERVAL_SECONDS) {
            return;
        }

        $this->lastWorkerPing = $now;
        if (!$this->isAlive()) {
            $event->getWorker()->stop();
        }
    }

    private function isAlive(): bool
    {
        try {
            $this->redis->ping();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
