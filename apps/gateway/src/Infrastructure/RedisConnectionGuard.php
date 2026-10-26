<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Baldinof\RoadRunnerBundle\Event\ForceKernelRebootEvent;
use Redis;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\WhenNot;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * phpredis 6 leaves a client whose command hit a Redis outage failed for good ("went away"), and
 * Symfony never re-creates its Redis connections (cache, lock). Long-running processes therefore
 * rebuild them: RoadRunner reboots the kernel after the response, a Messenger worker exits and
 * is restarted by the orchestrator. While Redis stays down this repeats after every request.
 *
 * The lock store has a connection of its own: it can stay broken while the cache one recovers
 * (it may sit unused during the outage), so both are probed.
 */
#[WhenNot(env: 'test')]
final readonly class RedisConnectionGuard
{
    private const string PROBE_LOCK = 'redis-connection-guard';

    public function __construct(
        #[Autowire(service: 'cache.default_redis_provider')]
        private Redis $redis,
        private LockFactory $locks,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onTerminate(): void
    {
        $broken = $this->brokenConnection();
        if ($broken !== null) {
            $this->dispatcher->dispatch(new ForceKernelRebootEvent(sprintf('The %s connection is broken.', $broken)));
        }
    }

    private function brokenConnection(): ?string
    {
        try {
            $this->redis->ping();
        } catch (Throwable) {
            return 'Redis cache';
        }

        try {
            // The framework's store service id is hashed, hence the factory; flock (dev) never fails.
            $this->locks->createLock(self::PROBE_LOCK, autoRelease: false)->isAcquired();
        } catch (Throwable) {
            return 'lock store';
        }

        return null;
    }
}
