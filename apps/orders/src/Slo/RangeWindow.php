<?php

declare(strict_types=1);

namespace App\Slo;

use InvalidArgumentException;

/**
 * Evaluation instants start, start + step, ... up to end (Unix seconds), as a Prometheus range query takes them.
 */
final readonly class RangeWindow
{
    /**
     * @param positive-int $start
     * @param positive-int $end
     * @param positive-int $step
     */
    public function __construct(
        public int $start,
        public int $end,
        public int $step,
    ) {
        if ($end < $start) {
            throw new InvalidArgumentException(sprintf('The end %d is before the start %d.', $end, $start));
        }
    }

    /**
     * @param positive-int $maxPoints
     *
     * @return non-empty-list<self> consecutive windows on the same grid, each of at most $maxPoints instants
     */
    public function chunks(int $maxPoints): array
    {
        $chunks = [];
        $start = $this->start;
        do {
            $end = min($start + ($maxPoints - 1) * $this->step, $this->end);
            $chunks[] = new self($start, $end, $this->step);
            $start = $end + $this->step;
        } while ($start <= $this->end);

        return $chunks;
    }
}
