<?php

declare(strict_types=1);

namespace App\E2e;

use Closure;
use RuntimeException;

final class Wait
{
    private const int POLL_MICROSECONDS = 250_000;

    /**
     * @param Closure(): bool $condition
     * @param string|Closure(): string $failureMessage a closure is called after the timeout, so it can describe the last poll
     */
    public static function until(Closure $condition, float $timeoutSeconds, string|Closure $failureMessage): void
    {
        if (!self::within($condition, $timeoutSeconds)) {
            throw new RuntimeException(is_string($failureMessage) ? $failureMessage : $failureMessage());
        }
    }

    /**
     * @param Closure(): bool $condition
     *
     * @return bool whether the condition held before the timeout
     */
    public static function within(Closure $condition, float $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            if ($condition()) {
                return true;
            }

            usleep(self::POLL_MICROSECONDS);
        } while (microtime(true) < $deadline);

        return false;
    }
}
