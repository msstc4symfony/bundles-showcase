<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Order;
use App\Message\PaymentProcessed;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PaymentProcessedHandler
{
    public function __construct(
        private OrderRepository $orders,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(PaymentProcessed $message): void
    {
        $order = $this->orders->find($message->orderId);
        if (!$order instanceof Order) {
            // The result can outrun the commit of the order; Messenger's retry delays let it land.
            throw new OrderNotVisibleYet($message->orderId);
        }

        $order->applyPayment($message->approved);
        $this->entityManager->flush();
    }
}
