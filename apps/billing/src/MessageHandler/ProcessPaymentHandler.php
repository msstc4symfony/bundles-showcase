<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Payment;
use App\Message\PaymentProcessed;
use App\Message\ProcessPayment;
use App\Payment\DeclinePolicy;
use App\Repository\PaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Latency;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
#[Slo(name: 'billing.process_payment', objectives: [new Availability(99.9), new Latency('1s', 99.0)])]
final readonly class ProcessPaymentHandler
{
    public function __construct(
        private PaymentRepository $payments,
        private EntityManagerInterface $entityManager,
        private DeclinePolicy $policy,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(ProcessPayment $message): void
    {
        // A redelivered message is answered again with the stored outcome instead of paying twice.
        $payment = $this->payments->findOneByOrderId($message->orderId);
        if (!$payment instanceof Payment) {
            $payment = new Payment($message->orderId, $message->amount, $this->policy->approves($message->amount));
            $this->entityManager->persist($payment);
            $this->entityManager->flush();
        }

        $this->bus->dispatch(new PaymentProcessed($message->orderId, $payment->isApproved()));
    }
}
