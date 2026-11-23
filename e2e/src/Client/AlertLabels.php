<?php

declare(strict_types=1);

namespace App\E2e\Client;

use Webmozart\Assert\Assert;

final class AlertLabels
{
    /**
     * @return array<non-empty-string, string>
     */
    public static function parse(mixed $labels): array
    {
        Assert::isArray($labels);
        $typed = [];
        foreach ($labels as $name => $value) {
            Assert::stringNotEmpty($name);
            Assert::string($value);
            $typed[$name] = $value;
        }

        return $typed;
    }

    /**
     * @param array<non-empty-string, string> $labels
     * @param array<non-empty-string, string> $wanted
     */
    public static function contain(array $labels, array $wanted): bool
    {
        return array_intersect_assoc($wanted, $labels) === $wanted;
    }
}
