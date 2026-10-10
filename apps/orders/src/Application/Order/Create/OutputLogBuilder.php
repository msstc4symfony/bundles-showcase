<?php

declare(strict_types=1);

namespace App\Application\Order\Create;

use Msstc4Symfony\LogicBundle\Application\Action\OutputInterface;
use Msstc4Symfony\LogicBundle\Application\Logging\Builder\OutputBuilderInterface;

final readonly class OutputLogBuilder implements OutputBuilderInterface
{
    /**
     * @return array{}|array{order_id: string, status: string, amount: int}
     */
    public function build(OutputInterface $output): array
    {
        if (!$output instanceof Output) {
            return [];
        }

        return ['order_id' => $output->order->id, 'status' => $output->order->status, 'amount' => $output->order->amount];
    }

    public function supports(OutputInterface $output): bool
    {
        return $output instanceof Output;
    }
}
