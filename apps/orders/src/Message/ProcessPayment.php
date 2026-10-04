<?php

declare(strict_types=1);

namespace App\Message;

final readonly class ProcessPayment
{
    public function __construct(
        public string $orderId,
        public int $amount,
    ) {
    }
}
