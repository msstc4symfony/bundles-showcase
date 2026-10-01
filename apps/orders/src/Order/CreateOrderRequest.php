<?php

declare(strict_types=1);

namespace App\Order;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateOrderRequest
{
    /** Upper bound of the PostgreSQL INTEGER column. */
    public const int MAX_AMOUNT = 2_147_483_647;

    public function __construct(
        #[Assert\NotNull]
        #[Assert\Type('integer')]
        #[Assert\Positive]
        #[Assert\LessThanOrEqual(self::MAX_AMOUNT)]
        public mixed $amount = null,
    ) {
    }
}
