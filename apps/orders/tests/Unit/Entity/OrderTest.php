<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Order;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class OrderTest extends TestCase
{
    #[TestWith([0])]
    #[TestWith([-1])]
    public function testRejectsANonPositiveAmount(int $amount): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Order($amount);
    }
}
