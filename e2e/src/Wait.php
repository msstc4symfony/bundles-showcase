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
     */
    public static function until(Closure $condition, float $timeoutSeconds, string $failureMessage): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            if ($condition()) {
                return;
            }

            usleep(self::POLL_MICROSECONDS);
        } while (microtime(true) < $deadline);

        throw new RuntimeException($failureMessage);
    }
}
