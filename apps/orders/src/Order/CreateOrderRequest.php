<?php

declare(strict_types=1);

namespace App\Order;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateOrderRequest
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Type('integer')]
        #[Assert\Positive]
        public mixed $amount = null,
    ) {
    }
}
