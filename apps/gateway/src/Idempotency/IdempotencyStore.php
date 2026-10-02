<?php

declare(strict_types=1);

namespace App\Idempotency;

use Closure;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Throwable;
use Webmozart\Assert\Assert;

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
     * Failures of $create propagate unchanged; lock or cache failures become IdempotencyUnavailable.
     *
     * @phpstan-impure
     *
     * @param string $fingerprint identifies the request; a key may not be reused for another one
     * @param Closure(): string $create
     *
     * @throws IdempotencyKeyReused
     * @throws IdempotencyUnavailable
     */
    public function remember(string $key, string $fingerprint, Closure $create): string
    {
        $id = 'idempotency_' . hash('sha256', $key);
        try {
            // The cache's own stampede lock is per process; RoadRunner workers need a shared one.
            $lock = $this->locks->createLock($id, self::LOCK_TTL_SECONDS);
            $lock->acquire(true);

            try {
                $stored = $this->cache->get($id, static function (ItemInterface $item) use ($create, $fingerprint): string {
                    $item->expiresAfter(self::TTL_SECONDS);

                    try {
                        $value = $create();
                    } catch (Throwable $exception) {
                        throw new CreationFailed($exception);
                    }

                    return json_encode(['fingerprint' => $fingerprint, 'value' => $value], JSON_THROW_ON_ERROR);
                });
            } finally {
                $lock->release();
            }
        } catch (CreationFailed $failure) {
            throw $failure->getPrevious() ?? $failure;
        } catch (Throwable $exception) {
            throw new IdempotencyUnavailable('Idempotency storage is unavailable.', $exception->getCode(), previous: $exception);
        }

        $entry = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        Assert::isArray($entry);
        Assert::string($entry['value'] ?? null);
        if (($entry['fingerprint'] ?? null) !== $fingerprint) {
            throw new IdempotencyKeyReused('The idempotency key was already used for another request.');
        }

        return $entry['value'];
    }
}
