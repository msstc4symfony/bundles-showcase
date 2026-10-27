<?php

declare(strict_types=1);

namespace App\E2e\Client;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Webmozart\Assert\Assert;

final readonly class LokiClient
{
    private const int LIMIT = 1000;

    private const string SERVICE_NAME_PATTERN = '/^[a-z0-9_-]+$/D';

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
        Assert::regex($service, self::SERVICE_NAME_PATTERN, sprintf('"%s" is not a compose service name.', $service));
        $this->assertRawString($text);

        return $this->run(sprintf('{service="%s"} |= `%s`', $service, $text), $sinceUnix);
    }

    /**
     * Whether one service sent records of one trace over OTLP. Loki's OTLP endpoint keeps trace_id as structured
     * metadata and labels the stream with service_name; the stderr lines Alloy ships carry a "service" label instead
     * (Loki also derives service_name from it), so the selector asks for streams without one.
     */
    public function hasOtlpRecordsOfTrace(string $service, string $traceId, float $sinceUnix): bool
    {
        Assert::regex($service, self::SERVICE_NAME_PATTERN, sprintf('"%s" is not a compose service name.', $service));
        Assert::regex($traceId, LogLine::TRACE_ID_PATTERN, sprintf('"%s" is not a trace id.', $traceId));

        $query = sprintf('{service_name="%s", service=""} | trace_id="%s"', $service, $traceId);

        return array_any($this->streams($this->queryRange($query, $sinceUnix)), fn (array $stream): bool => $stream['values'] !== []);
    }

    /**
     * @param non-empty-string $label
     *
     * @return list<LogLine>
     */
    private function query(string $label, string $value, float $sinceUnix): array
    {
        $this->assertRawString($value);

        return $this->run(sprintf('{service=~".+"} | json | %s=`%s`', $label, $value), $sinceUnix);
    }

    /**
     * @return list<LogLine>
     */
    private function run(string $query, float $sinceUnix): array
    {
        $data = $this->queryRange($query, $sinceUnix);

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
     * @return array<array-key, mixed>
     */
    private function queryRange(string $query, float $sinceUnix): array
    {
        return $this->http->request('GET', '/loki/api/v1/query_range', ['query' => [
            'query' => $query,
            'start' => (string) (int) ($sinceUnix * 1e9),
            'limit' => self::LIMIT,
            'direction' => 'backward',
        ]])->toArray();
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

    /**
     * A LogQL raw string (backticks) cannot escape a backtick: such a value would end the string early.
     */
    private function assertRawString(string $value): void
    {
        Assert::notContains($value, '`', 'A LogQL raw string cannot hold a backtick.');
    }
}
