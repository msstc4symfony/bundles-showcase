<?php

declare(strict_types=1);

namespace App\Tests\Unit\Slo;

use App\Slo\BackfillHistory;
use App\Slo\GeneratedEventSelector;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BackfillHistoryTest extends TestCase
{
    private const int NOW = 1_795_000_000;

    private const array LABELS = [
        'application' => 'showcase',
        'component' => 'orders',
        'slo' => 'orders.search_index',
        'objective' => 'availability',
        'revision' => '1c380329',
        'instance' => 'backfill',
    ];

    public function testTwoContiguousCounterSeriesOfOneSamplePerMinuteEndNow(): void
    {
        $samples = $this->samples(new BackfillHistory(60, 2, 0.002, 4, 0.03));

        self::assertSame(['good', 'bad'], array_keys($samples));
        foreach ($samples as $series) {
            $timestamps = array_keys($series);
            self::assertCount(60 * 1440 + 1, $series);
            self::assertSame(self::NOW, end($timestamps));
            self::assertSame(self::NOW - 60 * 86_400, $timestamps[0]);
            self::assertSame(range(self::NOW - 60 * 86_400, self::NOW, 60), $timestamps);
            self::assertSame(0, $series[$timestamps[0]]);
        }
    }

    public function testTheBurningTailIsAboveTheBudgetAndBelowThePageThresholds(): void
    {
        ['good' => $good, 'bad' => $bad] = $this->samples(new BackfillHistory(60, 2, 0.002, 4, 0.03));
        $tailStart = self::NOW - 4 * 86_400;

        $tailBad = $bad[self::NOW] - $bad[$tailStart];
        $tailTotal = $tailBad + $good[self::NOW] - $good[$tailStart];
        self::assertSame(4 * 1440 * 2, $tailTotal);
        self::assertEqualsWithDelta(0.03, $tailBad / $tailTotal, 0.0005);

        $beforeBad = $bad[$tailStart];
        self::assertEqualsWithDelta(0.002, $beforeBad / ($beforeBad + $good[$tailStart]), 0.0005);

        $hourAgo = self::NOW - 3600;
        $lastHour = $bad[self::NOW] - $bad[$hourAgo];
        self::assertLessThan(0.06, $lastHour / 120);
    }

    public function testEverySeriesCarriesTheGivenLabelsAndItsResult(): void
    {
        $lines = iterator_to_array(new BackfillHistory(2, 1, 0.0, 1, 0.5)->lines(self::LABELS, self::NOW), false);

        self::assertSame('# TYPE symfony_slo_events counter', $lines[0]);
        self::assertSame('# EOF', end($lines));
        $prefix = 'symfony_slo_events_total{application="showcase",component="orders",slo="orders.search_index",objective="availability",revision="1c380329",instance="backfill",result=';
        self::assertSame($prefix . '"good"} 0 ' . (self::NOW - 2 * 86_400), $lines[1]);
        self::assertSame($prefix . '"bad"} 720 ' . self::NOW, $lines[\count($lines) - 2]);
    }

    public function testATailAsLongAsTheHistoryIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BackfillHistory(4, 2, 0.002, 4, 0.03);
    }

    public function testTheLabelsAreThoseTheGeneratedRulesSelect(): void
    {
        $labels = new GeneratedEventSelector(\dirname(__DIR__, 3) . '/infrastructure/observability/prometheus/slo-recording.rules.yml')
            ->labels('orders.search_index', 'availability')
        ;

        self::assertSame(['application', 'component', 'slo', 'objective', 'revision'], array_keys($labels));
        self::assertSame(['showcase', 'orders', 'orders.search_index', 'availability'], \array_slice(array_values($labels), 0, 4));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $labels['revision']);
    }

    /**
     * @return array<string, array<int, int>> result => timestamp => value
     */
    private function samples(BackfillHistory $history): array
    {
        $samples = [];
        foreach ($history->lines(self::LABELS, self::NOW) as $line) {
            if (preg_match('/result="(\w+)"} (\d+) (\d+)$/', $line, $m) === 1) {
                $samples[$m[1]][(int) $m[3]] = (int) $m[2];
            }
        }

        return $samples;
    }
}
