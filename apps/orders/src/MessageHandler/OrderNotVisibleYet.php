<?php

declare(strict_types=1);

namespace App\MessageHandler;

use RuntimeException;

final class OrderNotVisibleYet extends RuntimeException
{
    public function __construct(string $orderId)
    {
        parent::__construct(sprintf('Order %s is not visible yet.', $orderId));
    }
}
