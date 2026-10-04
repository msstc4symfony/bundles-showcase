<?php

declare(strict_types=1);

namespace App\E2e\Context;

/**
 * Feature files use readable ids; a per-process suffix keeps every run independent of earlier
 * ones (idempotency keys live 24 h in Redis, logs stay in Loki).
 */
final class RunScoped
{
    private static ?string $suffix = null;

    public static function id(string $readable): string
    {
        self::$suffix ??= bin2hex(random_bytes(4));

        return $readable . '-' . self::$suffix;
    }
}
