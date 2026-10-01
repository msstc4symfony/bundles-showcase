<?php

declare(strict_types=1);

namespace App\Message;

final readonly class PaymentProcessed
{
    public function __construct(
        public string $orderId,
        public bool $approved,
    ) {
    }
}
