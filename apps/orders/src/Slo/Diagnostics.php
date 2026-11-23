<?php

declare(strict_types=1);

namespace App\Slo;

final class Diagnostics
{
    private function __construct()
    {
    }

    /**
     * JSON where it encodes, else the PHP literal or the type: an error message must not fail on what it reports (INF).
     */
    public static function describe(mixed $value): string
    {
        $json = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (\is_string($json) && json_last_error() === JSON_ERROR_NONE) {
            return $json;
        }

        return \is_scalar($value) ? var_export($value, true) : get_debug_type($value);
    }
}
