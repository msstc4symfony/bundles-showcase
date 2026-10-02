<?php

declare(strict_types=1);

namespace App\E2e\Client;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Webmozart\Assert\Assert;

final readonly class PrometheusClient
{
    private HttpClientInterface $http;

    public function __construct(?string $baseUri = null)
    {
        $baseUri ??= getenv('PROMETHEUS_URL');
        Assert::stringNotEmpty($baseUri, 'PROMETHEUS_URL is not set.');
        $this->http = HttpClient::createForBaseUri($baseUri, ['timeout' => 10]);
    }

    /**
     * Unix time of the oldest last successful scrape across the showcase targets; 0.0 while any target is down.
     *
     * @param positive-int $targets
     */
    public function oldestSuccessfulScrape(int $targets): float
    {
        // A failed scrape still records up=0 with a fresh timestamp, but brings no samples.
        // Counted per instance: right after "make up" a recreated container can briefly appear twice.
        if ($this->scalar('count(max by (instance) (up{job="showcase"} == 1))', null) < $targets) {
            return 0.0;
        }

        // timestamp() must wrap the raw selector: after a comparison it reports the evaluation time instead.
        return $this->scalar('min(max by (instance) (timestamp(up{job="showcase"}) and up{job="showcase"} == 1))', null);
    }

    /**
     * Sum of the instant vector (0.0 when it is empty), optionally evaluated at a past moment.
     */
    public function value(string $promQl, ?float $atUnix = null): float
    {
        return $this->scalar(sprintf('sum(%s)', $promQl), $atUnix);
    }

    private function scalar(string $promQl, ?float $atUnix): float
    {
        $query = ['query' => $promQl];
        if ($atUnix !== null) {
            $query['time'] = sprintf('%.3F', $atUnix);
        }

        $data = $this->http->request('GET', '/api/v1/query', ['query' => $query])->toArray();
        $payload = $data['data'] ?? null;
        Assert::isArray($payload);
        $result = $payload['result'] ?? null;
        Assert::isList($result);
        if ($result === []) {
            return 0.0;
        }

        Assert::isArray($result[0]);
        $sample = $result[0]['value'] ?? null;
        Assert::isList($sample);
        Assert::numeric($sample[1] ?? null);

        return (float) $sample[1];
    }
}
