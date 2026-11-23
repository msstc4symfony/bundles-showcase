<?php

declare(strict_types=1);

namespace App\Slo;

use InvalidArgumentException;

final readonly class RecordingRule
{
    private const string METRIC_NAME = '/^[a-zA-Z_:][a-zA-Z0-9_:]*$/';

    /**
     * @param non-empty-string $record
     * @param non-empty-string $expr
     * @param array<non-empty-string, string> $labels
     * @param positive-int $interval seconds between evaluations, its group's interval
     */
    public function __construct(
        public string $record,
        public string $expr,
        public array $labels,
        public int $interval,
    ) {
        if (preg_match(self::METRIC_NAME, $record) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a metric name.', $record));
        }
    }

    /**
     * Over-approximated (a quoted label value naming the record counts too): a wrong "yes" costs one more pass.
     */
    public function reads(self $other): bool
    {
        return preg_match('/(?<![\w:])' . preg_quote($other->record, '/') . '(?![\w:])/', $this->expr) === 1;
    }
}
