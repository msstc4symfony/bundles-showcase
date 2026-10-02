<?php

declare(strict_types=1);

namespace App\Tests\Unit\Idempotency;

use App\Idempotency\IdempotencyInProgress;
use App\Idempotency\IdempotencyKeyReused;
use App\Idempotency\IdempotencyStore;
use App\Idempotency\IdempotencyUnavailable;
use App\Tests\Support\FailingSaveCache;
use App\Tests\Support\RecordingLogger;
use Closure;
use ErrorException;
use Exception;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Log\NullLogger;
use ReflectionProperty;
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

        $this->expectException(IdempotencyKeyReused::class);

        try {
            $store->remember('key-a', 'body-2', $this->create('order-2'));
        } finally {
            self::assertSame(1, $this->creations);
        }
    }

    public function testAFailedCreationIsNotRememberedSoTheKeyCanBeRetried(): void
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
        $this->assertUnavailable(new IdempotencyStore(new ArrayAdapter(), new LockFactory($this->lockStore(saveFailure: new LockStorageException('Connection refused'))), new NullLogger()));
    }

    public function testAnIoWarningFromTheLockStoreMakesIdempotencyUnavailable(): void
    {
        // phpredis reports an unresolvable host as a warning, which the error handler turns into ErrorException.
        $this->assertUnavailable(new IdempotencyStore(new ArrayAdapter(), new LockFactory($this->lockStore(saveFailure: new ErrorException('getaddrinfo for redis failed'))), new NullLogger()));
    }

    public function testACacheThatCannotSaveMakesIdempotencyUnavailableBeforeCreating(): void
    {
        $this->assertUnavailable(new IdempotencyStore(new FailingSaveCache(1), new LockFactory(new InMemoryStore()), new NullLogger()));
    }

    public function testWhenTheAnswerCannotBeRememberedItIsStillReturnedAndRepeatsWaitInsteadOfCreatingAgain(): void
    {
        $logger = new RecordingLogger();
        $store = new IdempotencyStore(new FailingSaveCache(2), new LockFactory(new InMemoryStore()), $logger);

        self::assertSame('order-1', $store->remember('key-a', 'body-1', $this->create('order-1')));
        self::assertTrue($logger->has('warning', 'Could not remember an idempotent response'));

        $this->expectException(IdempotencyInProgress::class);

        try {
            $store->remember('key-a', 'body-1', $this->create('order-duplicate'));
        } finally {
            self::assertSame(1, $this->creations);
        }
    }

    public function testAnUnfinishedRequestWithAnotherBodyIsAReusedKey(): void
    {
        $store = new IdempotencyStore(new FailingSaveCache(2), new LockFactory(new InMemoryStore()), new NullLogger());
        $store->remember('key-a', 'body-1', $this->create('order-1'));

        $this->expectException(IdempotencyKeyReused::class);

        try {
            $store->remember('key-a', 'body-2', $this->create('order-2'));
        } finally {
            self::assertSame(1, $this->creations);
        }
    }

    public function testAFailingLockReleaseDoesNotHideTheResultAndIsLogged(): void
    {
        $logger = new RecordingLogger();
        $store = new IdempotencyStore(new ArrayAdapter(), new LockFactory($this->lockStore(failOnDelete: true)), $logger);

        self::assertSame('order-1', $store->remember('key-a', 'body-1', $this->create('order-1')));
        self::assertTrue($logger->has('warning', 'Could not release the idempotency lock'));
    }

    public function testThePendingMarkerExpiresSoonAndTheAnswerAfterADay(): void
    {
        $cache = new class extends ArrayAdapter {
            /** @var list<int> lifetimes in seconds, in save order */
            public array $lifetimes = [];

            #[Override]
            public function save(CacheItemInterface $item): bool
            {
                $expiry = new ReflectionProperty($item, 'expiry')->getValue($item);
                $this->lifetimes[] = is_numeric($expiry) ? (int) round((float) $expiry - microtime(true)) : 0;

                return parent::save($item);
            }
        };

        new IdempotencyStore($cache, new LockFactory(new InMemoryStore()), new NullLogger())->remember('key-a', 'body-1', $this->create('order-1'));

        self::assertSame([60, 86400], $cache->lifetimes);
    }

    public function testAnUnreadableEntryIsTreatedAsMissing(): void
    {
        $cache = new ArrayAdapter();
        $store = new IdempotencyStore($cache, new LockFactory(new InMemoryStore()), new NullLogger());
        $store->remember('key-a', 'body-1', $this->create('order-1'));
        foreach (array_keys($cache->getValues()) as $id) {
            $cache->save($cache->getItem($id)->set('not json'));
        }

        self::assertSame('order-again', $store->remember('key-a', 'body-1', $this->create('order-again')));
    }

    private function assertUnavailable(IdempotencyStore $store): void
    {
        try {
            $store->remember('key-a', 'body-1', $this->create('order-1'));
            self::fail('The unavailable storage must be reported.');
        } catch (IdempotencyUnavailable) {
            self::assertSame(0, $this->creations);
        }
    }

    /**
     * @return Closure(): string
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

    private function lockStore(?Exception $saveFailure = null, bool $failOnDelete = false): PersistingStoreInterface
    {
        return new readonly class($saveFailure, $failOnDelete) implements PersistingStoreInterface {
            public function __construct(
                private ?Exception $saveFailure,
                private bool $failOnDelete,
            ) {
            }

            #[Override]
            public function save(Key $key): void
            {
                if ($this->saveFailure instanceof Exception) {
                    throw $this->saveFailure;
                }
            }

            #[Override]
            public function delete(Key $key): void
            {
                if ($this->failOnDelete) {
                    throw new LockReleasingException('Connection lost');
                }
            }

            // Lock::release() checks exists() after delete(): a held-forever key would fail every release.
            #[Override]
            public function exists(Key $key): bool
            {
                return false;
            }

            #[Override]
            public function putOffExpiration(Key $key, float $ttl): void
            {
            }
        };
    }
}
