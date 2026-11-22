<?php

declare(strict_types=1);

namespace App\E2e;

use App\E2e\Client\PrometheusClient;
use App\E2e\Client\RabbitMqClient;

/**
 * Values read "as of" a moment must include everything before it: earlier scenarios' messages consumed and every
 * target scraped after that, otherwise their traffic counts as growth.
 */
final readonly class ScrapeBarrier
{
    private const int WAIT_SECONDS = 20;

    private const int SCRAPED_SERVICES = 3;

    private const int RABBITMQ_STATS_INTERVAL_SECONDS = 6;

    public function __construct(
        private PrometheusClient $prometheus = new PrometheusClient(),
        private RabbitMqClient $rabbitMq = new RabbitMqClient(),
    ) {
    }

    public function awaitIdleQueuesAndFreshScrape(): void
    {
        $this->awaitIdleQueues();
        $this->awaitFreshScrape();
    }

    /**
     * Idle must hold longer than the management API's statistics interval, or a stale "0" passes.
     */
    public function awaitIdleQueues(): void
    {
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
            self::WAIT_SECONDS,
            'RabbitMQ queues did not drain in time.',
        );
    }

    /**
     * Every target answered every scrape of the last $seconds: right after "make up" traffic would otherwise come
     * before Prometheus has seen the targets, and nothing could tell "no events yet" from "not scraped yet".
     *
     * @param positive-int $seconds
     */
    public function awaitTargetsScrapedFor(int $seconds, float $timeoutSeconds): void
    {
        $steady = sprintf(
            'count(max by (service) ((min_over_time(up{job="showcase"}[%1$ds]) == 1) and (count_over_time(up{job="showcase"}[%1$ds]) >= %2$d)))',
            $seconds,
            intdiv($seconds, 5),
        );
        Wait::until(
            fn (): bool => $this->prometheus->value($steady) >= self::SCRAPED_SERVICES,
            $timeoutSeconds,
            sprintf('Prometheus has not scraped every showcase target for %d seconds in time.', $seconds),
        );
    }

    public function awaitFreshScrape(): void
    {
        $requestedAt = microtime(true);
        Wait::until(
            fn (): bool => $this->prometheus->oldestSuccessfulScrape(self::SCRAPED_SERVICES) > $requestedAt,
            self::WAIT_SECONDS,
            'Prometheus did not scrape every showcase target successfully in time.',
        );
    }
}
