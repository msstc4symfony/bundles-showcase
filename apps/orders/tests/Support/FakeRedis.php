<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Override;
use Redis;
use RedisException;

/**
 * A \Redis that never touches the network: alive answers PING, dead fails like phpredis after an outage.
 */
final class FakeRedis extends Redis
{
    private int $pings = 0;

    public function __construct(private readonly bool $alive)
    {
        parent::__construct();
    }

    #[Override]
    public function ping(?string $message = null): string
    {
        $this->pings++;

        if (!$this->alive) {
            throw new RedisException('Redis server redis:6379 went away');
        }

        return $message ?? 'PONG';
    }

    public function pingCount(): int
    {
        return $this->pings;
    }
}
