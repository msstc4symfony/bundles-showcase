<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Override;
use Symfony\Component\Lock\Exception\LockStorageException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

final class UnreachableLockStore implements PersistingStoreInterface
{
    #[Override]
    public function save(Key $key): never
    {
        throw new LockStorageException('Connection refused');
    }

    #[Override]
    public function delete(Key $key): never
    {
        throw new LockStorageException('Connection refused');
    }

    #[Override]
    public function exists(Key $key): bool
    {
        return false;
    }

    #[Override]
    public function putOffExpiration(Key $key, float $ttl): never
    {
        throw new LockStorageException('Connection refused');
    }
}
