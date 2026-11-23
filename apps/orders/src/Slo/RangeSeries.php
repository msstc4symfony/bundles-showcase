<?php

declare(strict_types=1);

namespace App\Slo;

final readonly class RangeSeries
{
    /**
     * @param array<non-empty-string, string> $labels without __name__
     * @param non-empty-array<int, non-empty-string> $values Unix second => sample value as Prometheus prints it, in time order
     */
    public function __construct(
        public array $labels,
        public array $values,
    ) {
    }
}
