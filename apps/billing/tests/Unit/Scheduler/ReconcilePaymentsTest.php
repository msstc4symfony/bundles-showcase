<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler;

use App\Scheduler\ReconcilePayments;
use App\Scheduler\ReconciliationFailed;
use Override;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;

final class ReconcilePaymentsTest extends TestCase
{
    public function testARunSucceedsWithoutTheFailSwitch(): void
    {
        $this->expectNotToPerformAssertions();

        new ReconcilePayments($this->redisWithKeys([]))();
    }

    public function testARunFailsWhileTheFailSwitchExists(): void
    {
        $this->expectException(ReconciliationFailed::class);

        new ReconcilePayments($this->redisWithKeys([ReconcilePayments::FAIL_SWITCH]))();
    }

    public function testARunSucceedsWhenRedisCannotBeRead(): void
    {
        $this->expectNotToPerformAssertions();

        new ReconcilePayments($this->unreachableRedis())();
    }

    /**
     * @param list<non-empty-string> $keys
     */
    private function redisWithKeys(array $keys): Redis
    {
        return new class($keys) extends Redis {
            /**
             * @param list<non-empty-string> $keys
             */
            public function __construct(private readonly array $keys)
            {
                parent::__construct();
            }

            #[Override]
            public function exists(mixed $key, mixed ...$other_keys): int
            {
                return \count(array_filter(
                    [$key, ...$other_keys],
                    fn (mixed $candidate): bool => \in_array($candidate, $this->keys, true),
                ));
            }
        };
    }

    private function unreachableRedis(): Redis
    {
        return new class extends Redis {
            #[Override]
            public function exists(mixed $key, mixed ...$other_keys): never
            {
                throw new RedisException('Redis server redis:6379 went away');
            }
        };
    }
}
