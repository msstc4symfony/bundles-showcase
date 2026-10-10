<?php

declare(strict_types=1);

namespace App\Application\Order\Create;

use App\Order\OrderView;
use Msstc4Symfony\LogicBundle\Application\Action\OutputInterface;

final readonly class Output implements OutputInterface
{
    public function __construct(public OrderView $order)
    {
    }
}
