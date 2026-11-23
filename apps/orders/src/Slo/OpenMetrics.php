<?php

declare(strict_types=1);

namespace App\Slo;

/**
 * The OpenMetrics text that promtool tsdb create-blocks-from openmetrics reads.
 */
final class OpenMetrics
{
    private function __construct()
    {
    }

    /**
     * @param array<non-empty-string, string> $labels
     */
    public static function labelSet(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        $pairs = [];
        foreach ($labels as $name => $value) {
            $pairs[] = sprintf('%s="%s"', $name, addcslashes($value, "\\\"\n"));
        }

        return '{' . implode(',', $pairs) . '}';
    }
}
