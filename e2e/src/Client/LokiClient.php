<?php

declare(strict_types=1);

namespace App\E2e\Client;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Webmozart\Assert\Assert;

final readonly class LokiClient
{
    private const int LIMIT = 1000;

    private HttpClientInterface $http;

    public function __construct(?string $baseUri = null)
    {
        $baseUri ??= getenv('LOKI_URL');
        Assert::stringNotEmpty($baseUri, 'LOKI_URL is not set.');
        $this->http = HttpClient::createForBaseUri($baseUri, ['timeout' => 10]);
    }

    /**
     * Log records of every showcase process that carry the request id, newest first.
     *
     * @return list<LogLine>
     */
    public function linesFor(string $requestId, float $sinceUnix): array
    {
        $query = sprintf('{service=~".+"} | json | extra_request_id=`%s`', $requestId);
        $data = $this->http->request('GET', '/loki/api/v1/query_range', ['query' => [
            'query' => $query,
            'start' => (string) (int) ($sinceUnix * 1e9),
            'limit' => self::LIMIT,
            'direction' => 'backward',
        ]])->toArray();

        $lines = [];
        foreach ($this->streams($data) as $stream) {
            $service = $stream['stream']['service'] ?? null;
            if (!is_string($service)) {
                continue;
            }

            foreach ($stream['values'] as $value) {
                $lines[] = LogLine::fromJson($service, $value[1]);
            }
        }

        return $lines;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<array{stream: array<array-key, mixed>, values: list<array{0: string, 1: string}>}>
     */
    private function streams(array $data): array
    {
        $payload = $data['data'] ?? [];
        Assert::isArray($payload);
        $result = $payload['result'] ?? [];
        Assert::isList($result);

        $streams = [];
        foreach ($result as $stream) {
            Assert::isArray($stream);
            Assert::isArray($stream['stream'] ?? null);
            Assert::isList($stream['values'] ?? null);
            $values = [];
            foreach ($stream['values'] as $value) {
                Assert::isList($value);
                Assert::string($value[0] ?? null);
                Assert::string($value[1] ?? null);
                $values[] = [$value[0], $value[1]];
            }

            $streams[] = ['stream' => $stream['stream'], 'values' => $values];
        }

        return $streams;
    }
}
