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
        $sli = new RecordingRule('slo:sli:ratio', 'vector(1)', []);

        self::assertTrue(new RecordingRule('slo:budget', '1 - slo:sli:ratio{slo="a"}', [])->reads($sli));
        self::assertTrue(new RecordingRule('slo:budget', 'max_over_time(slo:sli:ratio[1h])', [])->reads($sli));
        self::assertFalse(new RecordingRule('slo:budget', 'slo:sli:ratio_total + xslo:sli:ratio', [])->reads($sli));
    }

    public function testARecordMustBeAMetricName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RecordingRule('slo sli', 'vector(1)', []);
    }

    public function testARuleRunsInThePassAfterTheRecordsItReads(): void
    {
        $raw = new RecordingRule('a', 'sum(raw)', []);
        $readsA = new RecordingRule('b', 'a * 2', []);
        $readsB = new RecordingRule('c', 'b + a', []);
        $alsoRaw = new RecordingRule('d', 'sum(raw)', []);

        $passes = new RecordingRules([$readsB, $readsA, $raw, $alsoRaw])->passes();

        self::assertSame([[$raw, $alsoRaw], [$readsA], [$readsB]], array_map(static fn (RecordingRules $pass): array => $pass->rules, $passes));
    }

    public function testRulesOfTheSameRecordDoNotWaitForEachOther(): void
    {
        $first = new RecordingRule('a', 'sum(raw{x="1"}) or a', ['x' => '1']);
        $second = new RecordingRule('a', 'sum(raw{x="2"})', ['x' => '2']);

        self::assertCount(1, new RecordingRules([$first, $second])->passes());
    }

    public function testACycleFails(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The recording rules a, b read each other in a cycle.');

        new RecordingRules([new RecordingRule('a', 'b', []), new RecordingRule('b', 'a', []), new RecordingRule('c', 'raw', [])])->passes();
    }

    public function testTheGeneratedErrorBudgetWaitsForTheSli(): void
    {
        $passes = new GeneratedRecordingRules(\dirname(__DIR__, 3) . '/infrastructure/observability/prometheus/slo-recording.rules.yml')
            ->ofObjective('orders.search_index', 'availability')
            ->passes()
        ;

        self::assertCount(2, $passes);
        self::assertContains('slo:sli:ratio', array_map(static fn (RecordingRule $rule): string => $rule->record, $passes[0]->rules));
        self::assertSame(['slo:error_budget:remaining'], array_map(static fn (RecordingRule $rule): string => $rule->record, $passes[1]->rules));
        foreach ($passes as $pass) {
            foreach ($pass as $rule) {
                self::assertSame(['orders.search_index', 'availability'], [$rule->labels['slo'] ?? null, $rule->labels['objective'] ?? null]);
            }
        }
    }
}
