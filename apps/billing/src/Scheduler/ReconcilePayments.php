<?php

declare(strict_types=1);

namespace App\Scheduler;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Latency;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Redis;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Showcase task for the scheduled-task SLO: there is nothing to reconcile, it fails on demand while
 * FAIL_SWITCH exists in billing's cache Redis (`redis-cli -n 4 set showcase:billing:reconcile:fail 1`).
 */
#[AsPeriodicTask(frequency: 15, schedule: 'billing')]
#[Slo(name: 'billing.reconcile_payments', objectives: [new Availability(99), new Latency('5s', 99)])]
final readonly class ReconcilePayments
{
    public const string FAIL_SWITCH = 'showcase:billing:reconcile:fail';

    public function __construct(
        #[Autowire(service: 'cache.default_redis_provider')]
        private Redis $redis,
    ) {
    }

    /**
     * @throws ReconciliationFailed
     */
    public function __invoke(): void
    {
        if ((int) $this->redis->exists(self::FAIL_SWITCH) > 0) {
            throw new ReconciliationFailed(sprintf('Reconciliation is switched to fail by the Redis key "%s".', self::FAIL_SWITCH));
        }
    }
}
