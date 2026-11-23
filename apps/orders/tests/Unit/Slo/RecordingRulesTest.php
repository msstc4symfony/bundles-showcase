<?php

declare(strict_types=1);

namespace App\Tests\Unit\Slo;

use App\Slo\GeneratedRecordingRules;
use App\Slo\RecordingRule;
use App\Slo\RecordingRules;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RecordingRulesTest extends TestCase
{
    public function testARuleReadsARecordAsAWholeMetricName(): void
    {
        $sli = $this->rule('slo:sli:ratio', 'vector(1)');

        self::assertTrue($this->rule('slo:budget', '1 - slo:sli:ratio{slo="a"}')->reads($sli));
        self::assertTrue($this->rule('slo:budget', 'max_over_time(slo:sli:ratio[1h])')->reads($sli));
        self::assertFalse($this->rule('slo:budget', 'slo:sli:ratio_total + xslo:sli:ratio')->reads($sli));
    }

    public function testARecordNamedInALabelValueCountsAsReadingIt(): void
    {
        // The documented over-approximation: it costs a pass, never misses a dependency.
        self::assertTrue($this->rule('b', 'sum(raw{source="a"})')->reads($this->rule('a', 'vector(1)')));
    }

    public function testARecordMustBeAMetricName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->rule('slo sli', 'vector(1)');
    }

    public function testARuleRunsInThePassAfterTheRecordsItReads(): void
    {
        $raw = $this->rule('a', 'sum(raw)');
        $readsA = $this->rule('b', 'a * 2');
        $readsB = $this->rule('c', 'b + a');
        $alsoRaw = $this->rule('d', 'sum(raw)');

        $passes = new RecordingRules([$readsB, $readsA, $raw, $alsoRaw])->passes();

        self::assertSame([[$raw, $alsoRaw], [$readsA], [$readsB]], array_map(static fn (RecordingRules $pass): array => $pass->rules, $passes));
    }

    public function testRulesOfTheSameRecordDoNotWaitForEachOther(): void
    {
        $first = new RecordingRule('a', 'sum(raw{x="1"}) or a', ['x' => '1'], 60);
        $second = new RecordingRule('a', 'sum(raw{x="2"})', ['x' => '2'], 60);

        self::assertCount(1, new RecordingRules([$first, $second])->passes());
    }

    public function testACycleFails(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The recording rules a, b read each other in a cycle.');

        new RecordingRules([$this->rule('a', 'b'), $this->rule('b', 'a'), $this->rule('c', 'raw')])->passes();
    }

    public function testTheErrorBudgetWaitsForTheSli(): void
    {
        $passes = new GeneratedRecordingRules(__DIR__ . '/Fixtures/slo-recording.rules.yml')
            ->ofObjective('orders.search_index', 'availability')
            ->passes()
        ;

        self::assertSame(
            [['slo:events:rate5m', 'slo:objective:target', 'slo:sli:ratio'], ['slo:error_budget:remaining']],
            array_map(static fn (RecordingRules $pass): array => array_map(static fn (RecordingRule $rule): string => $rule->record, $pass->rules), $passes),
        );
    }

    /**
     * @param non-empty-string $record
     * @param non-empty-string $expr
     */
    private function rule(string $record, string $expr): RecordingRule
    {
        return new RecordingRule($record, $expr, [], 60);
    }
}
