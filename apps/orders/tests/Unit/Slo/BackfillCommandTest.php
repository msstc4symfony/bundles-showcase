<?php

declare(strict_types=1);

namespace App\Tests\Unit\Slo;

use App\Slo\BackfillCommand;
use App\Slo\GeneratedEventSelector;
use App\Slo\GeneratedRecordingRules;
use DateTimeImmutable;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

final class BackfillCommandTest extends TestCase
{
    private const string NOW = '2026-11-23T10:00:00Z';

    private ?string $rulesFile = null;

    #[Override]
    protected function tearDown(): void
    {
        if ($this->rulesFile !== null) {
            unlink($this->rulesFile);
            $this->rulesFile = null;
        }
    }

    public function testTheSeriesCarryTheRuleLabelsAndTheBackfillInstance(): void
    {
        $tester = $this->tester();

        self::assertSame(0, $tester->execute(['--days' => '1', '--burning-days' => '0']));
        $lines = explode("\n", rtrim($tester->getDisplay(), "\n"));
        self::assertSame(
            'symfony_slo_events_total{application="showcase",component="orders",slo="orders.search_index",objective="availability",revision="1c380329",job="showcase",service="orders",instance="backfill",result="good"} 0 ' . (new DateTimeImmutable(self::NOW)->getTimestamp() - 86_400),
            $lines[1],
        );
        self::assertSame('# EOF', end($lines));
    }

    public function testNoBurningTailKeepsTheBaseRatio(): void
    {
        $tester = $this->tester();

        $tester->execute(['--days' => '1', '--events-per-minute' => '10', '--bad-ratio' => '0.01', '--burning-days' => '0', '--burning-bad-ratio' => '0.5']);

        self::assertStringContainsString('result="bad"} 144 ' . new DateTimeImmutable(self::NOW)->getTimestamp(), $tester->getDisplay());
    }

    #[TestWith(['--days', '0'])]
    #[TestWith(['--events-per-minute', '-1'])]
    #[TestWith(['--burning-days', '-1'])]
    #[TestWith(['--burning-days', 'x'])]
    #[TestWith(['--bad-ratio', 'x'])]
    #[TestWith(['--bad-ratio', '1.5'])]
    #[TestWith(['--burning-bad-ratio', '-0.1'])]
    #[TestWith(['--slo', ''])]
    public function testAnInvalidOptionIsRejected(string $option, string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->tester()->execute([$option => $value]);
    }

    private function tester(): CommandTester
    {
        $rules = <<<'YAML'
            groups:
              - name: slo-orders.search_index
                rules:
                  - record: 'slo:events:rate5m'
                    expr: 'sum(rate(symfony_slo_events_total{application="showcase",component=~"orders",slo="orders.search_index",objective="availability",revision="1c380329"}[5m]))'
                    labels: { slo: orders.search_index, objective: availability }
            YAML;
        $file = tempnam(sys_get_temp_dir(), 'rules');
        self::assertIsString($file);
        $this->rulesFile = $file;
        file_put_contents($file, $rules);

        return new CommandTester(new BackfillCommand(new MockClock(self::NOW), new GeneratedEventSelector(new GeneratedRecordingRules($file))));
    }
}
