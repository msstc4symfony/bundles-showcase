<?php

declare(strict_types=1);

namespace App\E2e\Context;

use App\E2e\Client\PrometheusClient;
use App\E2e\ScrapeBarrier;
use App\E2e\Wait;
use Behat\Behat\Context\Context;
use Behat\Step\Given;
use Behat\Step\Then;
use Closure;
use LogicException;
use RuntimeException;
use Webmozart\Assert\Assert;

final class MetricsContext implements Context
{
    private readonly PrometheusClient $prometheus;

    private readonly ScrapeBarrier $scrapeBarrier;

    private ?float $rememberedAt = null;

    public function __construct()
    {
        $this->prometheus = new PrometheusClient();
        $this->scrapeBarrier = new ScrapeBarrier($this->prometheus);
    }

    #[Given('I remember the current metric values')]
    public function iRememberTheCurrentMetricValues(): void
    {
        $this->scrapeBarrier->awaitIdleQueuesAndFreshScrape();
        $this->rememberedAt = microtime(true);
    }

    #[Then('within :seconds seconds :query grows by :delta')]
    public function grows(int $seconds, string $query, int $delta): void
    {
        $this->waitFor($seconds, $query, $this->baseline($query), static fn (float $growth): bool => abs($growth - $delta) < 1e-9, sprintf('grow by exactly %d', $delta));
    }

    #[Then('within :seconds seconds :query grows by at least :delta')]
    public function growsByAtLeast(int $seconds, string $query, int $delta): void
    {
        $this->waitFor($seconds, $query, $this->baseline($query), static fn (float $growth): bool => $growth >= $delta, sprintf('grow by at least %d', $delta));
    }

    #[Then('within :seconds seconds :query is at least :value')]
    public function isAtLeast(int $seconds, string $query, int $value): void
    {
        $this->waitFor($seconds, $query, 0.0, static fn (float $current): bool => $current >= $value, sprintf('become at least %d', $value));
    }

    #[Then('within :seconds seconds :query equals :value')]
    public function isExactly(int $seconds, string $query, int $value): void
    {
        $this->waitFor($seconds, $query, 0.0, static fn (float $current): bool => abs($current - $value) < 1e-9, sprintf('become %d', $value));
    }

    /**
     * Zero growth is true against any stale scrape, so the check waits for a scrape taken after this step began.
     */
    #[Then(':query has not grown after the next scrape')]
    public function hasNotGrownAfterTheNextScrape(string $query): void
    {
        $since = $this->rememberedAt ?? throw new LogicException('Remember the metric values first.');
        $before = $this->prometheus->value($query, $since);
        $this->scrapeBarrier->awaitFreshScrape();

        $after = $this->prometheus->value($query);
        Assert::same($after - $before, 0.0, sprintf('%s grew: %s → %s.', $query, $before, $after));
    }

    #[Then('within :seconds seconds :query has a series')]
    public function hasASeries(int $seconds, string $query): void
    {
        Wait::until(
            fn (): bool => $this->prometheus->hasSeries($query),
            $seconds,
            sprintf('%s returned no series.', $query),
        );
    }

    private function baseline(string $query): float
    {
        $since = $this->rememberedAt ?? throw new LogicException('Remember the metric values first.');

        return $this->prometheus->value($query, $since);
    }

    /**
     * @param Closure(float):bool $accepted receives the value of the query minus $baseline
     */
    private function waitFor(int $seconds, string $query, float $baseline, Closure $accepted, string $expectation): void
    {
        $current = $baseline;

        try {
            Wait::until(
                function () use ($query, $baseline, $accepted, &$current): bool {
                    $current = $this->prometheus->value($query);

                    return $accepted($current - $baseline);
                },
                $seconds,
                'metric did not change as expected',
            );
        } catch (RuntimeException $exception) {
            throw new RuntimeException(sprintf('%s changed: %s → %s, expected to %s.', $query, $baseline, $current, $expectation), $exception->getCode(), previous: $exception);
        }
    }
}
