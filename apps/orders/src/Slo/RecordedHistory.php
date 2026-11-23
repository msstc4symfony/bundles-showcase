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
     * @param non-empty-string $url Prometheus that holds the series the rules read
     *
     * @return Generator<int, string, mixed, void> lines without the trailing newline
     */
    public function lines(string $url, RecordingRules $rules, RangeWindow $window): Generator
    {
        /** @var array<non-empty-string, array<string, RangeSeries>> $families */
        $families = [];
        foreach ($rules as $rule) {
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
            foreach ($results as $result) {
                $labels = [...$result->labels, ...$rule->labels];
                ksort($labels);
                $key = serialize($labels);
                if (isset($families[$rule->record][$key])) {
                    throw new RuntimeException(sprintf('Two rules record the same series %s%s.', $rule->record, OpenMetrics::labelSet($labels)));
                }
                $families[$rule->record][$key] = new RangeSeries($labels, $result->values);
            }
        }

        foreach ($families as $record => $series) {
            yield sprintf('# TYPE %s gauge', $record);
            foreach ($series as $one) {
                $name = $record . OpenMetrics::labelSet($one->labels);
                foreach ($one->values as $timestamp => $value) {
                    yield sprintf('%s %s %d', $name, $value, $timestamp);
                }
            }
        }
        yield '# EOF';
    }
}
