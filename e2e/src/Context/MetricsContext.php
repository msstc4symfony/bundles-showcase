<?php

declare(strict_types=1);

namespace App\E2e\Context;

use App\E2e\Client\PrometheusClient;
use App\E2e\Client\RabbitMqClient;
use App\E2e\Wait;
use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Closure;
use LogicException;
use RuntimeException;

final class MetricsContext implements Context
{
    private const int SCRAPE_WAIT_SECONDS = 20;

    private const int SCRAPED_SERVICES = 3;

    private const int RABBITMQ_STATS_INTERVAL_SECONDS = 6;

    private const string LAST_ORDER_PLACEHOLDER = '{order}';

    private readonly PrometheusClient $prometheus;

    private readonly RabbitMqClient $rabbitMq;

    private ?float $rememberedAt = null;

    private OrderContext $orders;

    public function __construct()
    {
        $this->prometheus = new PrometheusClient();
        $this->rabbitMq = new RabbitMqClient();
    }

    #[BeforeScenario]
    public function gatherContexts(BeforeScenarioScope $scope): void
    {
        $this->orders = OrderContext::from($scope);
    }

    #[Given('I remember the current metric values')]
    public function iRememberTheCurrentMetricValues(): void
    {
        // Values are read later "as of" this moment: earlier scenarios' messages must be consumed and
        // every target scraped after that, otherwise their traffic counts as growth.
        // Idle must hold longer than the management API's statistics interval, or a stale "0" passes.
        $idleSince = null;
        Wait::until(
            function () use (&$idleSince): bool {
                if (!$this->rabbitMq->isIdle()) {
                    $idleSince = null;

                    return false;
                }

                $idleSince ??= microtime(true);

                return microtime(true) - $idleSince >= self::RABBITMQ_STATS_INTERVAL_SECONDS;
            },
            self::SCRAPE_WAIT_SECONDS,
            'RabbitMQ queues did not drain in time.',
        );
        $requestedAt = microtime(true);
        Wait::until(
            fn (): bool => $this->prometheus->oldestSuccessfulScrape(self::SCRAPED_SERVICES) > $requestedAt,
            self::SCRAPE_WAIT_SECONDS,
            'Prometheus did not scrape every showcase target successfully in time.',
        );
        $this->rememberedAt = microtime(true);
    }

    #[Then('within :seconds seconds :query grows by :delta')]
    public function grows(int $seconds, string $query, int $delta): void
    {
        $this->waitForGrowth($seconds, $query, static fn (float $growth): bool => abs($growth - $delta) < 1e-9, sprintf('by exactly %d', $delta));
    }

    #[Then('within :seconds seconds :query grows by at least :delta')]
    public function growsByAtLeast(int $seconds, string $query, int $delta): void
    {
        $this->waitForGrowth($seconds, $query, static fn (float $growth): bool => $growth >= $delta, sprintf('by at least %d', $delta));
    }

    /**
     * @param Closure(float):bool $accepted
     */
    private function waitForGrowth(int $seconds, string $query, Closure $accepted, string $expectation): void
    {
        if (str_contains($query, self::LAST_ORDER_PLACEHOLDER)) {
            $query = str_replace(self::LAST_ORDER_PLACEHOLDER, $this->orders->scenario->lastOrderId(), $query);
        }

        $since = $this->rememberedAt ?? throw new LogicException('Remember the metric values first.');
        $before = $this->prometheus->value($query, $since);
        $after = $before;

        try {
            Wait::until(
                function () use ($query, $before, $accepted, &$after): bool {
                    $after = $this->prometheus->value($query);

                    return $accepted($after - $before);
                },
                $seconds,
                'metric did not grow as expected',
            );
        } catch (RuntimeException) {
            throw new RuntimeException(sprintf('%s did not grow %s: %s → %s.', $query, $expectation, $before, $after));
        }
    }
}
