<?php

declare(strict_types=1);

namespace App\E2e\Context;

use App\E2e\Client\PrometheusClient;
use App\E2e\Wait;
use Behat\Behat\Context\Context;
use Behat\Step\Given;
use Behat\Step\Then;
use Closure;
use LogicException;
use RuntimeException;

final class MetricsContext implements Context
{
    private const int SCRAPE_WAIT_SECONDS = 20;

    private readonly PrometheusClient $prometheus;

    private ?float $rememberedAt = null;

    public function __construct()
    {
        $this->prometheus = new PrometheusClient();
    }

    #[Given('I remember the current metric values')]
    public function iRememberTheCurrentMetricValues(): void
    {
        // Values are read later "as of" this moment, so every target must have been scraped
        // after the previous scenarios' traffic; otherwise their requests count as growth.
        $requestedAt = microtime(true);
        Wait::until(
            fn (): bool => $this->prometheus->oldestScrape() > $requestedAt,
            self::SCRAPE_WAIT_SECONDS,
            'Prometheus did not scrape every showcase target in time.',
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
