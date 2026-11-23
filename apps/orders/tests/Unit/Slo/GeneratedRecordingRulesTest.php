<?php

declare(strict_types=1);

namespace App\Tests\Unit\Slo;

use App\Slo\GeneratedRecordingRules;
use App\Slo\RecordingRule;
use Override;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GeneratedRecordingRulesTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    #[Override]
    protected function tearDown(): void
    {
        array_map(unlink(...), $this->files);
    }

    public function testTheRulesOfOneObjectiveCarryTheirGroupsInterval(): void
    {
        $rules = new GeneratedRecordingRules(__DIR__ . '/Fixtures/slo-recording.rules.yml')->ofObjective('orders.search_index', 'availability');

        self::assertSame(
            [['slo:events:rate5m', 60], ['slo:objective:target', 60], ['slo:sli:ratio', 300], ['slo:error_budget:remaining', 300]],
            array_map(static fn (RecordingRule $rule): array => [$rule->record, $rule->interval], $rules->rules),
        );
    }

    public function testAlertingRulesAreSkippedAndScalarLabelsBecomeStrings(): void
    {
        $rules = $this->rules(<<<'YAML'
            groups:
              - name: g
                interval: 30s
                rules:
                  - alert: Burn
                    expr: 'up == 0'
                  - record: 'a'
                    expr: 'vector(1)'
                    labels: { slo: s, objective: o, window: 30, enabled: true, nested: [x] }
            YAML)->ofObjective('s', 'o');

        self::assertCount(1, $rules);
        self::assertSame(30, $rules->rules[0]->interval);
        self::assertSame(['slo' => 's', 'objective' => 'o', 'window' => '30', 'enabled' => '1'], $rules->rules[0]->labels);
    }

    #[TestWith(["      - record: 'a'\n"])]
    #[TestWith(["      - record: 'a'\n        expr: ''\n"])]
    #[TestWith(["      - expr: 'vector(1)'\n"])]
    #[TestWith(["      - just a string\n"])]
    public function testAMalformedRuleFails(string $rule): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('neither an alerting rule nor a recording rule with a record and an expr');

        $this->rules("groups:\n  - name: g\n    interval: 1m\n    rules:\n" . $rule)->ofObjective('s', 'o');
    }

    #[TestWith(['interval: 90'])]
    #[TestWith(['interval: 1d'])]
    #[TestWith(['interval: 0s'])]
    #[TestWith(['limit: 0'])]
    public function testAGroupWithoutAWholeIntervalFails(string $line): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not a whole number of s, m or h');

        $this->rules("groups:\n  - name: g\n    {$line}\n    rules:\n      - record: 'a'\n        expr: 'vector(1)'\n")->ofObjective('s', 'o');
    }

    #[TestWith(['99999999999999999999s'])]
    #[TestWith(['9223372036854775807h'])]
    public function testAnIntervalBeyondAnIntegerOfSecondsFails(string $interval): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("the interval {$interval} does not fit in an integer of seconds");

        $this->rules("groups:\n  - name: g\n    interval: {$interval}\n    rules:\n      - record: 'a'\n        expr: 'vector(1)'\n")->ofObjective('s', 'o');
    }

    public function testAnInfiniteIntervalIsReportedNotEncoded(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the interval INF is not a whole number of s, m or h');

        $this->rules("groups:\n  - name: g\n    interval: .inf\n    rules:\n      - record: 'a'\n        expr: 'vector(1)'\n")->ofObjective('s', 'o');
    }

    private function rules(string $yaml): GeneratedRecordingRules
    {
        $file = tempnam(sys_get_temp_dir(), 'rules');
        self::assertIsString($file);
        self::assertNotSame('', $file);
        $this->files[] = $file;
        file_put_contents($file, $yaml);

        return new GeneratedRecordingRules($file);
    }
}
