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
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            if ($condition()) {
                return;
            }

            usleep(self::POLL_MICROSECONDS);
        } while (microtime(true) < $deadline);

        throw new RuntimeException(is_string($failureMessage) ? $failureMessage : $failureMessage());
    }
}
