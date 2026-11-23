<?php

declare(strict_types=1);

namespace App\Slo;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Range queries against the Prometheus HTTP API. Started queries run concurrently until their results are read.
 */
final readonly class PrometheusRangeQuery
{
    /** The API rejects a range query of more points per series. */
    private const int MAX_POINTS = 11_000;

    /** Prometheus sends nothing until a query is done: increase[30d] chunks took 8 s under load; its own limit is 2m. */
    private const int TIMEOUT_SECONDS = 120;

    public function __construct(
        private HttpClientInterface $client,
    ) {
    }

    /**
     * @param non-empty-string $url base URL of the Prometheus server
     * @param non-empty-string $expr
     */
    public function start(string $url, string $expr, RangeWindow $window): PendingRangeQuery
    {
        $responses = [];
        foreach ($window->chunks(self::MAX_POINTS) as $chunk) {
            $responses[] = $this->client->request('POST', rtrim($url, '/') . '/api/v1/query_range', [
                'body' => ['query' => $expr, 'start' => $chunk->start, 'end' => $chunk->end, 'step' => $chunk->step],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
        }

        return new PendingRangeQuery($expr, $responses);
    }
}
