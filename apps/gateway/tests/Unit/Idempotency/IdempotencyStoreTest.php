<?php

declare(strict_types=1);

namespace App\Tests\Unit\Idempotency;

use App\Idempotency\IdempotencyKeyReused;
use App\Idempotency\IdempotencyStore;
use App\Idempotency\IdempotencyUnavailable;
use Closure;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\Exception\LockStorageException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;
use TypeError;

final class IdempotencyStoreTest extends TestCase
{
    private int $creations = 0;

    public function testFirstWriterWinsAndLaterCallsGetItsValue(): void
    {
        $store = $this->store();

        self::assertSame('order-1', $store->remember('key-a', 'body-1', $this->create('order-1')));
        self::assertSame('order-1', $store->remember('key-a', 'body-1', $this->create('order-duplicate')));
        self::assertSame('order-2', $store->remember('key-b', 'body-1', $this->create('order-2')));
        self::assertSame(2, $this->creations);
    }

    public function testReusingAKeyForADifferentRequestIsRejectedWithoutCreating(): void
    {
        $store = $this->store();
        $store->remember('key-a', 'body-1', $this->create('order-1'));

        try {
            $store->remember('key-a', 'body-2', $this->create('order-2'));
            self::fail('A reused key must be rejected.');
        } catch (IdempotencyKeyReused) {
            self::assertSame(1, $this->creations);
        }
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

        self::assertSame('order-1', $store->remember('key-a', 'body-1', $this->create('order-1')));
    }

    public function testProgrammingErrorsAreNotReportedAsUnavailability(): void
    {
        $this->expectException(TypeError::class);

        $this->store()->remember('key-a', 'body-1', static fn (): string => throw new TypeError('bug'));
    }

    public function testAnUnreachableLockStoreMakesIdempotencyUnavailable(): void
    {
        $store = new IdempotencyStore(new ArrayAdapter(), new LockFactory($this->lockStore(failOnSave: true)), new NullLogger());

        try {
            $store->remember('key-a', 'body-1', $this->create('order-1'));
            self::fail('An unreachable lock store must be reported.');
        } catch (IdempotencyUnavailable) {
            self::assertSame(0, $this->creations);
        }
    }

    public function testACacheThatCannotSaveMakesIdempotencyUnavailableBeforeCreating(): void
    {
        $cache = new class extends ArrayAdapter {
            #[Override]
            public function save(CacheItemInterface $item): bool
            {
                return false;
            }
        };
        $store = new IdempotencyStore($cache, new LockFactory(new InMemoryStore()), new NullLogger());

        try {
            $store->remember('key-a', 'body-1', $this->create('order-1'));
            self::fail('A cache that cannot save must be reported.');
        } catch (IdempotencyUnavailable) {
            self::assertSame(0, $this->creations);
        }
    }

    public function testAFailingLockReleaseDoesNotHideTheResult(): void
    {
        $store = new IdempotencyStore(new ArrayAdapter(), new LockFactory($this->lockStore(failOnDelete: true)), new NullLogger());

        self::assertSame('order-1', $store->remember('key-a', 'body-1', $this->create('order-1')));
    }

    public function testAnUnreadableEntryIsTreatedAsMissing(): void
    {
        $cache = new ArrayAdapter();
        $store = new IdempotencyStore($cache, new LockFactory(new InMemoryStore()), new NullLogger());
        $store->remember('key-a', 'body-1', $this->create('order-1'));
        foreach ($cache->getValues() as $id => $value) {
            $cache->save($cache->getItem($id)->set('not json'));
        }

        self::assertSame('order-again', $store->remember('key-a', 'body-1', $this->create('order-again')));
    }

    /**
     * @return Closure():string
     */
    private function create(string $value): Closure
    {
        return function () use ($value): string {
            $this->creations++;

            return $value;
        };
    }

    private function store(): IdempotencyStore
    {
        return new IdempotencyStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), new NullLogger());
    }

    private function lockStore(bool $failOnSave = false, bool $failOnDelete = false): PersistingStoreInterface
    {
        return new readonly class($failOnSave, $failOnDelete) implements PersistingStoreInterface {
            public function __construct(
                private bool $failOnSave,
                private bool $failOnDelete,
            ) {
            }

            public function save(Key $key): void
            {
                if ($this->failOnSave) {
                    throw new LockStorageException('Connection refused');
                }
            }

            public function delete(Key $key): void
            {
                if ($this->failOnDelete) {
                    throw new LockReleasingException('Connection lost');
                }
            }

            public function exists(Key $key): bool
            {
                return true;
            }

            public function putOffExpiration(Key $key, float $ttl): void
            {
            }
        };
    }
}
