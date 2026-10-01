<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment;

use App\Payment\DeclinePolicy;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class DeclinePolicyTest extends TestCase
{
    #[TestWith([100000, true])]
    #[TestWith([100001, false])]
    #[TestWith([1, true])]
    public function testApprovesUpToTheLimit(int $amount, bool $approved): void
    {
        self::assertSame($approved, new DeclinePolicy(100000)->approves($amount));
    }
}
