<?php

declare(strict_types=1);

namespace App\Tests\Unit\Slo;

use App\Slo\BackfillRecordedCommand;
use App\Slo\GeneratedRecordingRules;
use App\Slo\PrometheusRangeQuery;
use App\Slo\RecordedHistory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BackfillRecordedCommandTest extends TestCase
{
    /** @var list<string> queries sent to Prometheus */
    private array $queries = [];

    public function testPassesCountsTheGeneratedPasses(): void
    {
        $tester = $this->tester();

        self::assertSame(0, $tester->execute(['--passes' => true]));
        self::assertSame("2\n", $tester->getDisplay());
        self::assertSame([], $this->queries);
    }

    public function testAPassWritesTheHistoryOfItsRulesOnly(): void
    {
        $tester = $this->tester();

        self::assertSame(0, $tester->execute(['--pass' => '2', '--prometheus-url' => 'http://prometheus.test', '--start' => '300', '--end' => '600']));
        self::assertCount(1, $this->queries);
        self::assertStringStartsWith('1 - (1 - slo:sli:ratio{', $this->queries[0]);
        self::assertMatchesRegularExpression(
            '/^# TYPE slo:error_budget:remaining gauge\n'
            . 'slo:error_budget:remaining\{application="showcase",objective="availability",revision="[0-9a-f]{8}",slo="orders.search_index",window="30d"\} 0\.5 300\n'
            . '# EOF\n$/',
            $tester->getDisplay(),
        );
    }

    /**
     * @param array<non-empty-string, string|true> $options
     */
    #[TestWith([['--pass' => '3']])]
    #[TestWith([['--pass' => '0']])]
    #[TestWith([['--pass' => '1', '--start' => '600', '--end' => '300']])]
    #[TestWith([['--pass' => '1', '--step' => '0']])]
    #[TestWith([['--pass' => '1', '--prometheus-url' => '']])]
    #[TestWith([['--passes' => true, '--objective' => 'throughput']])]
    public function testAnInvalidOptionIsRejected(array $options): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->tester()->execute([...['--prometheus-url' => 'http://prometheus.test', '--start' => '300', '--end' => '600'], ...$options]);
    }

    private function tester(): CommandTester
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertIsString($options['body'] ?? null);
            parse_str($options['body'], $body);
            self::assertIsString($body['query'] ?? null);
            $this->queries[] = $body['query'];

            return new MockResponse(json_encode(
                ['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => [['metric' => [], 'values' => [[300, '0.5']]]]]],
                JSON_THROW_ON_ERROR,
            ));
        });

        return new CommandTester(new BackfillRecordedCommand(
            new GeneratedRecordingRules(\dirname(__DIR__, 3) . '/infrastructure/observability/prometheus/slo-recording.rules.yml'),
            new RecordedHistory(new PrometheusRangeQuery($client)),
        ));
    }
}
