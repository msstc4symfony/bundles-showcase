<?php

declare(strict_types=1);

namespace App\E2e\Client;

use Webmozart\Assert\Assert;

final class WireList
{
    /**
     * @return list<array<array-key, mixed>>
     */
    public static function ofArrays(mixed $value): array
    {
        Assert::isList($value);
        Assert::allIsArray($value);

        return $value;
    }
}
