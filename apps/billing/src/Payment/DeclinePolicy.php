<?php

declare(strict_types=1);

namespace App\Payment;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class DeclinePolicy
{
    public function __construct(
        #[Autowire(env: 'int:BILLING_DECLINE_ABOVE')]
        private int $declineAbove,
    ) {
    }

    public function approves(int $amount): bool
    {
        return $amount <= $this->declineAbove;
    }
}
