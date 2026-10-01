<?php

declare(strict_types=1);

namespace App\Idempotency;

use Closure;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Idempotency key → the outcome of the first request that carried it.
 */
final readonly class IdempotencyStore
{
    private const int TTL_SECONDS = 86400;

    private const float LOCK_TTL_SECONDS = 30.0;

    public function __construct(
        private CacheInterface $cache,
        private LockFactory $locks,
    ) {
    }

    /**
     * A failed $create is not remembered: the next request with the same key retries it.
     *
     * @phpstan-impure
     *
     * @param Closure(): string $create
     */
    public function remember(string $key, Closure $create): string
    {
        $id = 'idempotency_' . hash('sha256', $key);
        // The cache's own stampede lock is per process; RoadRunner workers need a shared one.
        $lock = $this->locks->createLock($id, self::LOCK_TTL_SECONDS);
        $lock->acquire(true);

        try {
            return $this->cache->get($id, static function (ItemInterface $item) use ($create): string {
                $item->expiresAfter(self::TTL_SECONDS);

                return $create();
            });
        } finally {
            $lock->release();
        }
    }
}
