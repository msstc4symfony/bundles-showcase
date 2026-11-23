<?php

declare(strict_types=1);

namespace App\Tests\Unit\Slo;

use App\Slo\PrometheusRangeQuery;
use App\Slo\RangeWindow;
use App\Slo\RecordedHistory;
use App\Slo\RecordingRule;
use App\Slo\RecordingRules;
use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RecordedHistoryTest extends TestCase
{
    private const string URL = 'http://prometheus.test';

    /** @var list<array{query: string, start: string, end: string, step: string}> */
    private array $requests = [];

    public function testEachRuleIsOneFamilyOfItsLabelsOverTheResultsAcrossChunks(): void
    {
        $history = $this->history(static fn (string $query, int $start): array => match ($query) {
            'sum(raw)' => [['metric' => ['__name__' => 'raw', 'slo' => 'from-query', 'job' => 'x'], 'values' => [[$start, '0.5'], [$start + 300, 'NaN']]]],
            default => [['metric' => [], 'values' => [[$start, '0.99']]]],
        });
        $rules = new RecordingRules([
            new RecordingRule('slo:events:rate5m', 'sum(raw)', ['slo' => 'orders.search_index']),
            new RecordingRule('slo:objective:target', 'vector(0.99)', ['slo' => 'orders.search_index']),
        ]);
        // 11 001 instants: one chunk of the API's 11 000 points and one of a single point.
        $window = new RangeWindow(300, 300 * 11_001, 300);

        $lines = iterator_to_array($history->lines(self::URL, $rules, $window), false);

        self::assertSame([
            '# TYPE slo:events:rate5m gauge',
            'slo:events:rate5m{job="x",slo="orders.search_index"} 0.5 300',
            'slo:events:rate5m{job="x",slo="orders.search_index"} NaN 600',
            'slo:events:rate5m{job="x",slo="orders.search_index"} 0.5 3300300',
            'slo:events:rate5m{job="x",slo="orders.search_index"} NaN 3300600',
            '# TYPE slo:objective:target gauge',
            'slo:objective:target{slo="orders.search_index"} 0.99 300',
            'slo:objective:target{slo="orders.search_index"} 0.99 3300300',
            '# EOF',
        ], $lines);
        self::assertSame(
            [['sum(raw)', '300', '3300000', '300'], ['sum(raw)', '3300300', '3300300', '300']],
            array_map(static fn (array $request): array => [$request['query'], $request['start'], $request['end'], $request['step']], \array_slice($this->requests, 0, 2)),
        );
    }

    public function testARuleWithoutResultsFails(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The slo:sli:ratio rule found nothing at http://prometheus.test between 2026-11-23 00:00:00 and 2026-11-23 01:00:00 UTC');

        iterator_to_array($this->history(static fn (): array => [])->lines(
            self::URL,
            new RecordingRules([new RecordingRule('slo:sli:ratio', 'sum(raw)', [])]),
            new RangeWindow(1_795_392_000, 1_795_395_600, 300),
        ));
    }

    public function testTwoRulesRecordingTheSameSeriesFail(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Two rules record the same series a{slo="x"}.');

        iterator_to_array($this->history(static fn (string $query, int $start): array => [['metric' => [], 'values' => [[$start, '1']]]])->lines(
            self::URL,
            new RecordingRules([new RecordingRule('a', 'vector(1)', ['slo' => 'x']), new RecordingRule('a', 'vector(2)', ['slo' => 'x'])]),
            new RangeWindow(300, 600, 300),
        ));
    }

    public function testAnErrorOfPrometheusFails(): void
    {
        $history = new RecordedHistory(new PrometheusRangeQuery(new MockHttpClient(new MockResponse(
            json_encode(['status' => 'error', 'errorType' => 'bad_data', 'error' => 'parse error'], JSON_THROW_ON_ERROR),
            ['http_code' => 400],
        ))));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Prometheus did not evaluate "sum(raw)" (HTTP 400): parse error');

        iterator_to_array($history->lines(self::URL, new RecordingRules([new RecordingRule('a', 'sum(raw)', [])]), new RangeWindow(300, 600, 300)));
    }

    public function testAFractionalTimestampFails(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expected [whole Unix second, "value"]');

        iterator_to_array($this->history(static fn (): array => [['metric' => [], 'values' => [[300.5, '1']]]])->lines(
            self::URL,
            new RecordingRules([new RecordingRule('a', 'sum(raw)', [])]),
            new RangeWindow(300, 600, 300),
        ));
    }

    /**
     * @param Closure(string, int):(list<array{metric: array<string, string>, values: list<array{int|float, string}>}>) $result
     */
    private function history(Closure $result): RecordedHistory
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($result): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame(self::URL . '/api/v1/query_range', $url);
            self::assertIsString($options['body'] ?? null);
            parse_str($options['body'], $body);
            $request = [];
            foreach (['query', 'start', 'end', 'step'] as $name) {
                self::assertIsString($body[$name] ?? null);
                $request[$name] = $body[$name];
            }
            $this->requests[] = $request;

            return new MockResponse(json_encode(
                ['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => $result($request['query'], (int) $request['start'])]],
                JSON_THROW_ON_ERROR,
            ));
        });

        return new RecordedHistory(new PrometheusRangeQuery($client));
    }
}
