<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Order;
use App\Message\PaymentProcessed;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PaymentProcessedHandler
{
    public function __construct(
        private OrderRepository $orders,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PaymentProcessed $message): void
    {
        $order = $this->orders->find($message->orderId);
        if (!$order instanceof Order) {
            $this->logger->warning('Payment result for an unknown order', ['order_id' => $message->orderId]);

            return;
        }

        $order->applyPayment($message->approved);
        $this->entityManager->flush();
    }
}
