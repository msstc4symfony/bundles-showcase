<?php

declare(strict_types=1);

namespace App\E2e;

use Closure;
use RuntimeException;

final class Wait
{
    public const float POLL_SECONDS = 0.25;

    /**
     * @param Closure(): bool $condition
     * @param string|Closure(): string $failureMessage a closure is called after the timeout, so it can describe the last poll
     */
    public static function until(
        Closure $condition,
        float $timeoutSeconds,
        string|Closure $failureMessage,
        float $pollSeconds = self::POLL_SECONDS,
    ): void {
        if (!self::within($condition, $timeoutSeconds, $pollSeconds)) {
            throw new RuntimeException(is_string($failureMessage) ? $failureMessage : $failureMessage());
        }
    }

    /**
     * @param Closure(): bool $condition
     *
     * @return bool whether the condition held before the timeout
     */
    public static function within(Closure $condition, float $timeoutSeconds, float $pollSeconds = self::POLL_SECONDS): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (true) {
            if ($condition()) {
                return true;
            }

            $left = $deadline - microtime(true);
            if ($left <= 0) {
                break;
            }

            usleep((int) (min($pollSeconds, $left) * 1_000_000));
        }

        return false;
    }
}
