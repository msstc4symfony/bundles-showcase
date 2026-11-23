<?php

declare(strict_types=1);

namespace App\Slo;

use Generator;
use InvalidArgumentException;

/**
 * Cumulative good/bad counters of one SLO objective as OpenMetrics text for promtool tsdb create-blocks-from:
 * one sample per minute over $days, the last one at "now", with a higher bad ratio in the last $burningDays.
 */
final readonly class BackfillHistory
{
    public const string FAMILY = 'symfony_slo_events';

    private const int STEP_SECONDS = 60;

    private const int MINUTES_PER_DAY = 1440;

    /** Keeps float error from rounding an exact bad-event count down by one in floor(). */
    private const float FLOAT_SLACK = 1e-9;

    /**
     * @param positive-int $days
     * @param positive-int $eventsPerMinute
     * @param non-negative-int $burningDays
     */
    public function __construct(
        private int $days,
        private int $eventsPerMinute,
        private float $badRatio,
        private int $burningDays,
        private float $burningBadRatio,
    ) {
        if ($burningDays >= $days) {
            throw new InvalidArgumentException(sprintf(
                'The burning tail (%d days) must be shorter than the history (%d days).',
                $burningDays,
                $days,
            ));
        }
        foreach ([$badRatio, $burningBadRatio] as $ratio) {
            if ($ratio < 0.0 || $ratio >= 1.0) {
                throw new InvalidArgumentException(sprintf('A bad ratio must be in [0, 1), got %s.', $ratio));
            }
        }
    }

    /**
     * Series stay contiguous (all "good" samples, then all "bad"), each in time order, as OpenMetrics requires.
     *
     * @param non-empty-array<non-empty-string, string> $labels every label but "result"
     * @param positive-int $now Unix timestamp of the last sample
     *
     * @return Generator<int, string, mixed, void> lines without the trailing newline
     */
    public function lines(array $labels, int $now): Generator
    {
        yield '# TYPE ' . self::FAMILY . ' counter';
        foreach (['good', 'bad'] as $result) {
            $series = self::FAMILY . '_total' . OpenMetrics::labelSet([...$labels, 'result' => $result]);
            foreach ($this->samples($now) as $timestamp => [$total, $bad]) {
                yield sprintf('%s %d %d', $series, $result === 'bad' ? $bad : $total - $bad, $timestamp);
            }
        }
        yield '# EOF';
    }

    /**
     * @param positive-int $now
     *
     * @return Generator<int, array{non-negative-int, int}, mixed, void> timestamp => [total, bad]
     */
    private function samples(int $now): Generator
    {
        $steps = $this->days * self::MINUTES_PER_DAY;
        $burningFrom = $steps - $this->burningDays * self::MINUTES_PER_DAY;
        for ($step = 0; $step <= $steps; $step++) {
            $baseSteps = min($step, $burningFrom);
            $burningSteps = $step - $baseSteps;
            // Rounded down from the exact expectation, so the ratio over any long range is the configured one.
            $expectedBad = $this->eventsPerMinute
                * ($baseSteps * $this->badRatio + $burningSteps * $this->burningBadRatio);

            yield $now - ($steps - $step) * self::STEP_SECONDS => [
                $step * $this->eventsPerMinute,
                (int) floor($expectedBad + self::FLOAT_SLACK),
            ];
        }
    }
}
