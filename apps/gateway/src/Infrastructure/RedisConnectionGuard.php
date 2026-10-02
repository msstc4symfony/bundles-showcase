<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Baldinof\RoadRunnerBundle\Event\ForceKernelRebootEvent;
use Redis;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\WhenNot;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * phpredis 6 leaves a client whose command hit a Redis outage failed for good ("went away"), and
 * Symfony never re-creates its Redis connections (cache, lock). Long-running processes therefore
 * rebuild them: RoadRunner reboots the kernel after the response, a Messenger worker exits and
 * is restarted by the orchestrator.
 */
#[WhenNot(env: 'test')]
final readonly class RedisConnectionGuard
{
    public function __construct(
        #[Autowire(service: 'cache.default_redis_provider')]
        private Redis $redis,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onTerminate(): void
    {
        if (!$this->isAlive()) {
            $this->dispatcher->dispatch(new ForceKernelRebootEvent('The Redis connection is broken.'));
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
