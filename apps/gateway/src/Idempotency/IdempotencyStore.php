<?php

declare(strict_types=1);

namespace App\Idempotency;

use Closure;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockException;
use Symfony\Component\Lock\LockFactory;
use Throwable;

/**
 * Idempotency key → the outcome of the first request that carried it.
 */
final readonly class IdempotencyStore
{
    private const string KEY_PREFIX = 'idempotency_v2_';

    private const int TTL_SECONDS = 86400;

    private const float LOCK_TTL_SECONDS = 30.0;

    public function __construct(
        private CacheItemPoolInterface $cache,
        private LockFactory $locks,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * A failed $create is not remembered and propagates unchanged: the next request with the key retries it.
     *
     * @phpstan-impure
     *
     * @param Closure(): string $create
     *
     * @throws IdempotencyKeyReused the key already answered a request with another payload
     * @throws IdempotencyUnavailable the lock or the cache cannot be used, so $create was not called
     */
    public function remember(string $key, string $payload, Closure $create): string
    {
        $id = self::KEY_PREFIX . hash('sha256', $key);
        $fingerprint = hash('sha256', $payload);
        // The cache's own stampede lock is per process; RoadRunner workers need a shared one.
        // Released explicitly below; auto-release would retry a failed release from the destructor, outside any catch.
        $lock = $this->locks->createLock($id, self::LOCK_TTL_SECONDS, autoRelease: false);

        try {
            $lock->acquire(true);
        } catch (LockException $exception) {
            throw new IdempotencyUnavailable('The idempotency lock store is unavailable.', $exception->getCode(), previous: $exception);
        }

        try {
            return $this->rememberLocked($id, $fingerprint, $create);
        } finally {
            try {
                $lock->release();
            } catch (LockException $exception) {
                // The lock expires on its own; the answer must not be lost because of it.
                $this->logger->warning('Could not release the idempotency lock', ['exception' => $exception]);
            }
        }
    }

    /**
     * @param Closure(): string $create
     */
    private function rememberLocked(string $id, string $fingerprint, Closure $create): string
    {
        $item = $this->cache->getItem($id);
        $entry = $item->isHit() ? IdempotencyEntry::fromStored($item->get()) : null;
        if ($entry instanceof IdempotencyEntry && $entry->value !== null) {
            if ($entry->fingerprint !== $fingerprint) {
                throw new IdempotencyKeyReused('The idempotency key was already used for another request.');
            }

            return $entry->value;
        }

        // Cache adapters swallow storage errors (a failed read looks like a miss), so writing a marker is
        // the only way to learn the store works before the irreversible call.
        $item->set(new IdempotencyEntry($fingerprint, null)->toStored())->expiresAfter(self::TTL_SECONDS);
        if (!$this->cache->save($item)) {
            throw new IdempotencyUnavailable('The idempotency cache is unavailable.');
        }

        try {
            $value = $create();
        } catch (Throwable $exception) {
            $this->cache->deleteItem($id);

            throw $exception;
        }

        $item->set(new IdempotencyEntry($fingerprint, $value)->toStored());
        if (!$this->cache->save($item)) {
            $this->logger->warning('Could not remember an idempotent response', ['key_id' => $id]);
        }

        return $value;
    }
}
