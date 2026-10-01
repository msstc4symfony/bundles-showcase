<?php

declare(strict_types=1);

namespace App\Tests\Unit\Idempotency;

use App\Idempotency\IdempotencyStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class IdempotencyStoreTest extends TestCase
{
    public function testFirstWriterWinsAndLaterCallsGetItsValue(): void
    {
        $store = $this->store();

        self::assertSame('order-1', $store->remember('key-a', static fn (): string => 'order-1'));
        self::assertSame('order-1', $store->remember('key-a', static fn (): string => 'order-duplicate'));
        self::assertSame('order-2', $store->remember('key-b', static fn (): string => 'order-2'));
    }

    public function testAFailedCreationIsNotRememberedAndReleasesTheKey(): void
    {
        $store = $this->store();

        try {
            $store->remember('key-a', static fn (): string => throw new RuntimeException('upstream down'));
            self::fail('The creation failure must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('upstream down', $exception->getMessage());
        }

        self::assertSame('order-1', $store->remember('key-a', static fn (): string => 'order-1'));
    }

    private function store(): IdempotencyStore
    {
        return new IdempotencyStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()));
    }
}
