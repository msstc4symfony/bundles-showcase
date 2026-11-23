<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Slo\GeneratedRecordingRules;
use App\Slo\RecordingRule;
use App\Slo\RecordingRules;
use PHPUnit\Framework\TestCase;

/**
 * The backfill's contract with the slo:generate output: a regeneration that changes it fails here, not in the stack.
 */
final class GeneratedRecordingRulesTest extends TestCase
{
    public function testTheBackfilledObjectiveIsTwoPassesWithTheErrorBudgetLast(): void
    {
        $passes = new GeneratedRecordingRules(\dirname(__DIR__, 2) . '/infrastructure/observability/prometheus/slo-recording.rules.yml')
            ->ofObjective('orders.search_index', 'availability')
            ->passes()
        ;

        self::assertCount(2, $passes);
        self::assertCount(13, $passes[0]);
        self::assertSame(['slo:error_budget:remaining'], array_map(static fn (RecordingRule $rule): string => $rule->record, $passes[1]->rules));
        self::assertSame([60, 300], array_values(array_unique(array_merge(...array_map(
            static fn (RecordingRules $pass): array => array_map(static fn (RecordingRule $rule): int => $rule->interval, $pass->rules),
            $passes,
        )))));
    }
}
