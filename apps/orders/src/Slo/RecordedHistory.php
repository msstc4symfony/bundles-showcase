<?php

declare(strict_types=1);

namespace App\Slo;

use Generator;
use RuntimeException;

/**
 * What recording rules would have recorded over a past window, as OpenMetrics for promtool tsdb
 * create-blocks-from openmetrics: each rule's expression as a range query, its labels over the result's, named
 * after its record, like promtool tsdb create-blocks-from rules does.
 */
final readonly class RecordedHistory
{
    public function __construct(
        private PrometheusRangeQuery $query,
    ) {
    }

    /**
     * Rules sharing a record form one metric family, as OpenMetrics requires. A rule without any result fails: the
     * history covers every rule of the objective, so nothing means it is not loaded or carries other labels.
     *
     * Each rule is evaluated on its group's interval from $start, as Prometheus would have recorded it.
     *
     * @param non-empty-string $url Prometheus that holds the series the rules read
     * @param positive-int $start first evaluation, Unix seconds
     * @param positive-int $end last evaluation at or before, Unix seconds
     *
     * @return Generator<int, string, mixed, void> lines without the trailing newline
     */
    public function lines(string $url, RecordingRules $rules, int $start, int $end): Generator
    {
        $byRecord = [];
        foreach ($rules as $rule) {
            $byRecord[$rule->record][] = $rule;
        }

        // Streamed per family: holding every rule's 86 401 one-minute points peaked at 112 MiB of a 128 MiB limit.
        foreach ($byRecord as $record => $family) {
            yield sprintf('# TYPE %s gauge', $record);
            $seen = [];
            foreach ($family as $rule) {
                // One rule at a time: all rules at once took 10-18 s against 7 s, the queries compete for Prometheus' CPU.
                foreach ($this->series($url, $rule, new RangeWindow($start, $end, $rule->interval)) as $result) {
                    $labels = [...$result->labels, ...$rule->labels];
                    ksort($labels);
                    $key = serialize($labels);
                    if (isset($seen[$key])) {
                        throw new RuntimeException(sprintf('Two rules record the same series %s%s.', $record, OpenMetrics::labelSet($labels)));
                    }
                    $seen[$key] = true;
                    $name = $record . OpenMetrics::labelSet($labels);
                    foreach ($result->values as $timestamp => $value) {
                        yield sprintf('%s %s %d', $name, $value, $timestamp);
                    }
                }
            }
        }
        yield '# EOF';
    }

    /**
     * @param non-empty-string $url
     *
     * @return non-empty-list<RangeSeries>
     */
    private function series(string $url, RecordingRule $rule, RangeWindow $window): array
    {
        $results = $this->query->start($url, $rule->expr, $window)->series();
        if ($results === []) {
            throw new RuntimeException(sprintf(
                'The %s rule found nothing at %s between %s and %s UTC: is the history it reads loaded?',
                $rule->record,
                $url,
                gmdate('Y-m-d H:i:s', $window->start),
                gmdate('Y-m-d H:i:s', $window->end),
            ));
        }

        return $results;
    }
}
