<?php

declare(strict_types=1);

namespace App\Tests\Unit\Idempotency;

use App\Idempotency\IdempotencyKeyReused;
use App\Idempotency\IdempotencyStore;
use App\Idempotency\IdempotencyUnavailable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\Exception\LockStorageException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

final class IdempotencyStoreTest extends TestCase
{
    public function testFirstWriterWinsAndLaterCallsGetItsValue(): void
    {
        $store = $this->store();

        self::assertSame('order-1', $store->remember('key-a', 'body-1', static fn (): string => 'order-1'));
        self::assertSame('order-1', $store->remember('key-a', 'body-1', static fn (): string => 'order-duplicate'));
        self::assertSame('order-2', $store->remember('key-b', 'body-1', static fn (): string => 'order-2'));
    }

    public function testReusingAKeyForADifferentRequestIsRejected(): void
    {
        $store = $this->store();
        $store->remember('key-a', 'body-1', static fn (): string => 'order-1');

        $this->expectException(IdempotencyKeyReused::class);

        $store->remember('key-a', 'body-2', static fn (): string => 'order-2');
    }

    public function testAFailedCreationIsNotRememberedAndReleasesTheKey(): void
    {
        $store = $this->store();

        try {
            $store->remember('key-a', 'body-1', static fn (): string => throw new RuntimeException('upstream down'));
            self::fail('The creation failure must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('upstream down', $exception->getMessage());
        }

        self::assertSame('order-1', $store->remember('key-a', 'body-1', static fn (): string => 'order-1'));
    }

    public function testAnUnreachableLockStoreMakesIdempotencyUnavailable(): void
    {
        $brokenLocks = new class implements PersistingStoreInterface {
            public function save(Key $key): never
            {
                throw new LockStorageException('Connection refused');
            }

            public function delete(Key $key): void
            {
            }

            public function exists(Key $key): bool
            {
                return false;
            }

            public function putOffExpiration(Key $key, float $ttl): void
            {
            }
        };
        $store = new IdempotencyStore(new ArrayAdapter(), new LockFactory($brokenLocks));

        $this->expectException(IdempotencyUnavailable::class);

        $store->remember('key-a', 'body-1', static fn (): string => 'order-1');
    }

    private function store(): IdempotencyStore
    {
        return new IdempotencyStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()));
    }
}
