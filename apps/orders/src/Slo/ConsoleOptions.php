<?php

declare(strict_types=1);

namespace App\Slo;

use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Typed values of the backfill commands' options.
 */
final readonly class ConsoleOptions
{
    public function __construct(
        private InputInterface $input,
    ) {
    }

    /**
     * @return non-empty-string
     */
    public function string(string $name): string
    {
        $value = $this->input->getOption($name);

        return \is_string($value) && $value !== ''
            ? $value
            : throw new InvalidArgumentException(sprintf('--%s must be set and not empty.', $name));
    }

    /**
     * @return positive-int
     */
    public function positiveInt(string $name): int
    {
        $value = filter_var($this->string($name), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return \is_int($value) && $value > 0
            ? $value
            : throw new InvalidArgumentException(sprintf('--%s must be a positive integer.', $name));
    }

    /**
     * @return non-negative-int
     */
    public function nonNegativeInt(string $name): int
    {
        $value = filter_var($this->string($name), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return \is_int($value) && $value >= 0
            ? $value
            : throw new InvalidArgumentException(sprintf('--%s must be a non-negative integer.', $name));
    }

    public function ratio(string $name): float
    {
        $value = filter_var($this->string($name), FILTER_VALIDATE_FLOAT);

        return \is_float($value)
            ? $value
            : throw new InvalidArgumentException(sprintf('--%s must be a number.', $name));
    }
}
