<?php

declare(strict_types=1);

namespace App\E2e\Context;

use App\E2e\Client\DockerClient;
use Behat\Behat\Context\Context;
use Behat\Hook\AfterScenario;
use Behat\Step\When;
use Webmozart\Assert\Assert;

/**
 * Switches billing's scheduled App\Scheduler\ReconcilePayments task to fail through its Redis key.
 */
final class SchedulerContext implements Context
{
    // App\Scheduler\ReconcilePayments::FAIL_SWITCH: the e2e image has no app code to import it from.
    private const string FAIL_SWITCH = 'showcase:billing:reconcile:fail';

    // Created on first use: other scenarios do not need the docker socket.
    private ?DockerClient $docker = null;

    /** @var non-negative-int|null the database of billing's REDIS_CACHE_DSN, read from its scheduler container */
    private ?int $database = null;

    private bool $switched = false;

    #[AfterScenario]
    public function restoreAfterScenario(): void
    {
        if ($this->switched) {
            $this->theBillingReconciliationIsRestored();
        }
    }

    /**
     * @param positive-int $seconds
     */
    #[When('the billing reconciliation fails for :seconds seconds')]
    public function theBillingReconciliationFailsFor(int $seconds): void
    {
        // The expiry restores the task also when the run is killed before its AfterScenario hook.
        Assert::same($this->redis('set', self::FAIL_SWITCH, '1', 'EX', (string) $seconds), 'OK');
        $this->switched = true;
    }

    #[When('the billing reconciliation is restored')]
    public function theBillingReconciliationIsRestored(): void
    {
        Assert::inArray($this->redis('del', self::FAIL_SWITCH), ['0', '1']);
        $this->switched = false;
    }

    private function redis(string ...$arguments): string
    {
        $this->docker ??= new DockerClient();
        $this->database ??= $this->billingCacheDatabase($this->docker);
        $command = ['redis-cli', '-n', (string) $this->database, ...array_values($arguments)];

        return trim($this->docker->exec('redis', $command));
    }

    /**
     * @return non-negative-int
     */
    private function billingCacheDatabase(DockerClient $docker): int
    {
        $dsn = trim($docker->exec('billing-scheduler', ['printenv', 'REDIS_CACHE_DSN']));
        Assert::eq(
            preg_match('~^redis://[^/?]+/(\d+)(?:\?|$)~', $dsn, $matches),
            1,
            sprintf('No database in REDIS_CACHE_DSN "%s".', $dsn),
        );
        $database = (int) $matches[1];
        Assert::natural($database);

        return $database;
    }
}
