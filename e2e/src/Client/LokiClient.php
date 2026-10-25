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
     * Logs of one trace: trace_id is a top-level field of the record (logger-bundle's json_top_level_extra).
     *
     * @return list<LogLine>
     */
    public function linesOfTrace(string $traceId, float $sinceUnix): array
    {
        return $this->query('trace_id', $traceId, $sinceUnix);
    }

    /**
     * @param 'runtime_id' $field
     *
     * @return list<LogLine>
     */
    public function linesWhereExtra(string $field, string $value, float $sinceUnix): array
    {
        return $this->query('extra_' . $field, $value, $sinceUnix);
    }

    /**
     * Lines of one compose service that contain a text.
     *
     * @return list<LogLine>
     */
    public function linesOfServiceContaining(string $service, string $text, float $sinceUnix): array
    {
        return $this->run(sprintf('{service="%s"} |= `%s`', $service, $text), $sinceUnix);
    }

    /**
     * @param non-empty-string $label
     *
     * @return list<LogLine>
     */
    private function query(string $label, string $value, float $sinceUnix): array
    {
        return $this->run(sprintf('{service=~".+"} | json | %s=`%s`', $label, $value), $sinceUnix);
    }

    /**
     * @return list<LogLine>
     */
    private function run(string $query, float $sinceUnix): array
    {
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
