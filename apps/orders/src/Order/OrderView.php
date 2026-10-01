<?php

declare(strict_types=1);

namespace App\Order;

use App\Entity\Order;

final readonly class OrderView
{
    public function __construct(
        public string $id,
        public string $status,
        public int $amount,
    ) {
    }

    public static function of(Order $order): self
    {
        return new self($order->id(), $order->status()->value, $order->amount());
    }
}
